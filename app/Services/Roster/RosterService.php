<?php

declare(strict_types=1);

namespace App\Services\Roster;

use App\Audit\AuditLogger;
use App\Enums\BuildingRoleType;
use App\Enums\ContactSource;
use App\Models\Apartment;
use App\Models\ResidentContact;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The ONE write path of the resident roster (ADMIN-007, ROUND2-SPEC §5.6): create / update / delete / mark reviewed /
 * mark moved out. Every write is authorised for the actor (ResidentContactPolicy — gate L-R + role + stairwell),
 * validated, sealed field by field with ContactVault (ULID generated BEFORE sealing, so the AEAD binds the row id) and
 * audited with ids and field NAMES only. Import commits write through here too (one `import.commit` audit row
 * summarises them instead of one row per contact).
 */
final class RosterService
{
    public function __construct(
        private readonly ContactVault $vault,
        private readonly RosterLinker $linker,
    ) {}

    /**
     * @throws RosterActionFailed
     */
    public function create(User $actor, Apartment $apartment, ContactData $data, ContactSource $source = ContactSource::Admin, ?string $importRunId = null, bool $audit = true): ResidentContact
    {
        Gate::forUser($actor)->authorize('create', [ResidentContact::class, $apartment]);
        $this->assertValid($data);

        $contact = new ResidentContact;
        $contact->forceFill([
            'id' => strtolower((string) Str::ulid()),
            'apartment_id' => $apartment->id,
            'relation' => $data->relation,
            'source' => $source,
            'import_run_id' => $importRunId,
            'key_version' => self::currentKeyVersion(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->seal($contact, $data);
        $contact->save();

        if ($audit) {
            AuditLogger::record('roster.create', $contact, data: ['apartment_id' => (int) $apartment->id, 'source' => $source->value], actorRole: self::actorRole($actor), actorUserId: (int) $actor->id);
        }

        $this->linker->linkContact($contact, $data->email, $actor);

        return $contact;
    }

    /**
     * Re-seals every field (ROUND2-SPEC §5.3 "Saving re-seals all fields").
     *
     * @throws RosterActionFailed
     */
    public function update(User $actor, ResidentContact $contact, ContactData $data, bool $audit = true): ResidentContact
    {
        Gate::forUser($actor)->authorize('update', $contact);
        $this->assertValid($data);

        $before = $this->fingerprint($contact);
        $oldEmailBidx = $contact->email_bidx;

        $contact->forceFill(['relation' => $data->relation, 'updated_by' => $actor->id]);
        $this->seal($contact, $data);
        if ($contact->email_bidx !== $oldEmailBidx) {
            $contact->user_id = null; // a changed e-mail no longer proves the account link
        }
        $contact->save();

        if ($audit) {
            $changed = array_keys(array_diff_assoc($this->fingerprint($contact), $before));
            AuditLogger::record('roster.update', $contact, $this->subjects($contact), data: ['apartment_id' => (int) $contact->apartment_id, 'fields' => $changed], actorRole: self::actorRole($actor), actorUserId: (int) $actor->id);
        }

        $this->linker->linkContact($contact, $data->email, $actor);

        return $contact;
    }

    public function delete(User $actor, ResidentContact $contact): void
    {
        Gate::forUser($actor)->authorize('delete', $contact);

        $subjects = $this->subjects($contact);
        AuditLogger::record('roster.delete', $contact, $subjects, data: ['apartment_id' => (int) $contact->apartment_id], actorRole: self::actorRole($actor), actorUserId: (int) $actor->id);
        $contact->delete();
    }

    public function markReviewed(User $actor, ResidentContact $contact): void
    {
        Gate::forUser($actor)->authorize('update', $contact);

        $contact->forceFill(['reviewed_at' => now(), 'updated_by' => $actor->id])->save();
        AuditLogger::record('roster.update', $contact, $this->subjects($contact), data: ['apartment_id' => (int) $contact->apartment_id, 'fields' => ['reviewed_at']], actorRole: self::actorRole($actor), actorUserId: (int) $actor->id);
    }

    /**
     * "Izvācies" (pruned 30 days later by prune:roster) — or back to current with $movedOut = false.
     */
    public function markMovedOut(User $actor, ResidentContact $contact, bool $movedOut = true): void
    {
        Gate::forUser($actor)->authorize('update', $contact);

        $contact->forceFill(['moved_out_at' => $movedOut ? now() : null, 'updated_by' => $actor->id])->save();
        AuditLogger::record('roster.update', $contact, $this->subjects($contact), data: ['apartment_id' => (int) $contact->apartment_id, 'fields' => ['moved_out_at']], actorRole: self::actorRole($actor), actorUserId: (int) $actor->id);
    }

    /**
     * The building's current ContactVault key version (rows are moved together by contacts:rekey).
     */
    public static function currentKeyVersion(): int
    {
        return max(1, (int) ResidentContact::query()->max('key_version'));
    }

    /**
     * The actor's ROLE for audit rendering ("Kas skatīja manus datus" shows roles, never names).
     */
    public static function actorRole(User $actor): string
    {
        return $actor->isBuildingAdmin(CurrentBuilding::get()) ? BuildingRoleType::BuildingAdmin->value : BuildingRoleType::SectionElder->value;
    }

    /**
     * Validation of a contact's plaintext (codes only).
     *
     * @throws RosterActionFailed
     */
    public static function assertValid(ContactData $data): void
    {
        if ($data->isEmpty()) {
            throw new RosterActionFailed('empty');
        }
        if ($data->name !== null && mb_strlen($data->name) > (int) config('majas.roster.name_max')) {
            throw new RosterActionFailed('name_too_long');
        }
        if (count($data->phones) > (int) config('majas.roster.max_phones')) {
            throw new RosterActionFailed('phones_too_many');
        }
        foreach ($data->phones as $phone) {
            if (preg_match('/^\+[1-9]\d{6,14}$/', $phone) !== 1) {
                throw new RosterActionFailed('phone_invalid');
            }
        }
        if ($data->email !== null && (mb_strlen($data->email) > 254 || filter_var($data->email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new RosterActionFailed('email_invalid');
        }
        if ($data->comment !== null && mb_strlen($data->comment) > (int) config('majas.roster.comment_max')) {
            throw new RosterActionFailed('comment_too_long');
        }
    }

    private function seal(ResidentContact $contact, ContactData $data): void
    {
        $buildingId = (int) ($contact->building_id ?? CurrentBuilding::id());
        $rowId = (string) $contact->id;
        $version = (int) $contact->key_version;
        $email = $data->email !== null ? ContactVault::normalizeEmail($data->email) : null;

        $contact->forceFill([
            'name_ciphertext' => $data->name !== null ? $this->vault->seal($data->name, $buildingId, $rowId, 'name', $version) : null,
            'phones_ciphertext' => $data->phones !== [] ? $this->vault->seal((string) json_encode(array_values($data->phones)), $buildingId, $rowId, 'phones', $version) : null,
            'phone_bidx' => array_map(fn (string $p): string => $this->vault->bidx($buildingId, 'phone', $p), array_values($data->phones)),
            'email_ciphertext' => $email !== null ? $this->vault->seal($email, $buildingId, $rowId, 'email', $version) : null,
            'email_bidx' => $email !== null ? $this->vault->bidx($buildingId, 'email', $email) : null,
            'bis_registered' => $data->bisRegistered,
            'whatsapp_member' => $data->whatsappMember,
            'comment_ciphertext' => $data->comment !== null ? $this->vault->seal($data->comment, $buildingId, $rowId, 'comment', $version) : null,
        ]);
    }

    /**
     * Which fields are set / what the indexes are — compared before and after an update to name the changed FIELDS.
     *
     * @return array<string, string>
     */
    private function fingerprint(ResidentContact $contact): array
    {
        return [
            'relation' => $contact->relation->value,
            'name' => $contact->name_ciphertext === null ? '' : 'set',
            'phones' => bin2hex(implode('', $contact->phone_bidx)),
            'email' => bin2hex((string) $contact->email_bidx),
            'bis_registered' => var_export($contact->bis_registered, true),
            'whatsapp_member' => var_export($contact->whatsapp_member, true),
            'comment' => $contact->comment_ciphertext === null ? '' : 'set',
        ];
    }

    /**
     * @return list<int>
     */
    private function subjects(ResidentContact $contact): array
    {
        return $contact->user_id !== null ? [(int) $contact->user_id] : [];
    }
}
