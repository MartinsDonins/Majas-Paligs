<?php

declare(strict_types=1);

namespace App\Services\Roster;

use App\Audit\AuditLogger;
use App\Models\Apartment;
use App\Models\AuditEntry;
use App\Models\ResidentContact;
use App\Models\User;
use App\Support\PhoneNormalizer;
use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Read side of the roster (ADMIN-007, ROUND2-SPEC §5.3) — one of the few ContactVault::open() callers.
 *
 * - masked(): what lists show — the name, phones as `+371 2•••••45` (screen readers: "slēpts numurs, beidzas ar
 *   45"), e-mail as `j•••@…`, flags. Masked lists are audited `roster.list` once per user per 10 minutes per filter set.
 * - reveal() / forEdit(): unmasked values for one apartment / one contact — policy `reveal` (mfa step-up), at most 100
 *   per user per day, audited `roster.reveal` with the linked accounts as data subjects.
 * - matchKeys(): what the import needs to recognise existing contacts (names lower-cased, blind indexes).
 */
final class RosterViewer
{
    public const REVEAL_ACTION = 'roster.reveal';

    public function __construct(private readonly ContactVault $vault) {}

    /**
     * @return array{id: string, name: ?string, phones: list<array{mask: string, last: string}>, email: ?string, relation: string, bis: ?bool, whatsapp: ?bool, comment: bool, reviewed: bool, linked: bool, moved_out: bool}
     */
    public function masked(ResidentContact $contact): array
    {
        $phones = array_map(static fn (string $p): array => ['mask' => PhoneNormalizer::mask($p), 'last' => PhoneNormalizer::lastDigits($p)], $this->phones($contact));
        $email = $this->vault->openField($contact, 'email');

        return [
            'id' => (string) $contact->id,
            'name' => $this->vault->openField($contact, 'name'),
            'phones' => $phones,
            'email' => $email !== null ? self::maskEmail($email) : null,
            'relation' => $contact->relation->value,
            'bis' => $contact->bis_registered,
            'whatsapp' => $contact->whatsapp_member,
            'comment' => $contact->hasComment(),
            'reviewed' => $contact->reviewed_at !== null && $contact->reviewed_at->gt(now()->subMonths(12)),
            'linked' => $contact->user_id !== null,
            'moved_out' => $contact->moved_out_at !== null,
        ];
    }

    /**
     * "Rādīt kontaktus" for one apartment.
     *
     * @return list<array{id: string, name: ?string, relation: string, phones: list<string>, email: ?string}>
     *
     * @throws RosterActionFailed step_up | cap
     */
    public function reveal(User $actor, Apartment $apartment): array
    {
        if (! Gate::forUser($actor)->allows('reveal', [ResidentContact::class, $apartment])) {
            throw new RosterActionFailed('step_up');
        }
        $this->assertCap($actor);

        $contacts = ResidentContact::query()->where('apartment_id', $apartment->id)->orderBy('created_at')->get();
        $subjects = $contacts->pluck('user_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all();

        AuditLogger::record(self::REVEAL_ACTION, $apartment, $subjects, data: [
            'apartment_id' => (int) $apartment->id,
            'contact_ids' => $contacts->pluck('id')->map(static fn ($id): string => (string) $id)->all(),
        ], actorRole: RosterService::actorRole($actor), actorUserId: (int) $actor->id);

        return $contacts->map(fn (ResidentContact $c): array => [
            'id' => (string) $c->id,
            'name' => $this->vault->openField($c, 'name'),
            'relation' => $c->relation->value,
            'phones' => $this->phones($c),
            'email' => $this->vault->openField($c, 'email'),
        ])->values()->all();
    }

    /**
     * Unmasked form values of one contact for "Labot" (counts as a reveal: audited, capped).
     *
     * @return array{relation: string, name: ?string, phones: list<string>, email: ?string, bis_registered: string, whatsapp_member: string, comment: ?string}
     *
     * @throws RosterActionFailed step_up | cap
     */
    public function forEdit(User $actor, ResidentContact $contact): array
    {
        if (! Gate::forUser($actor)->allows('reveal', $contact)) {
            throw new RosterActionFailed('step_up');
        }
        $this->assertCap($actor);

        AuditLogger::record(self::REVEAL_ACTION, $contact, $contact->user_id !== null ? [(int) $contact->user_id] : [], data: [
            'apartment_id' => (int) $contact->apartment_id,
            'contact_ids' => [(string) $contact->id],
        ], actorRole: RosterService::actorRole($actor), actorUserId: (int) $actor->id);

        return [
            'relation' => $contact->relation->value,
            'name' => $this->vault->openField($contact, 'name'),
            'phones' => $this->phones($contact),
            'email' => $this->vault->openField($contact, 'email'),
            'bis_registered' => self::flagValue($contact->bis_registered),
            'whatsapp_member' => self::flagValue($contact->whatsapp_member),
            'comment' => $this->vault->openField($contact, 'comment'),
        ];
    }

    /**
     * Plaintext of one contact for the import update merge (server-side only, never rendered).
     */
    public function plain(ResidentContact $contact): ContactData
    {
        return new ContactData(
            relation: $contact->relation,
            name: $this->vault->openField($contact, 'name'),
            phones: $this->phones($contact),
            email: $this->vault->openField($contact, 'email'),
            bisRegistered: $contact->bis_registered,
            whatsappMember: $contact->whatsapp_member,
            comment: $this->vault->openField($contact, 'comment'),
        );
    }

    /**
     * Existing contacts of the given apartments in the shape the import matcher needs.
     *
     * @param  list<int>  $apartmentIds
     * @return array<int, list<array{id: string, name: ?string, email_bidx: ?string, phone_bidx: list<string>}>>
     */
    public function matchKeys(array $apartmentIds): array
    {
        $out = [];
        foreach (ResidentContact::query()->whereIn('apartment_id', $apartmentIds)->get() as $c) {
            $name = $this->vault->openField($c, 'name');
            $out[(int) $c->apartment_id][] = [
                'id' => (string) $c->id,
                'name' => $name !== null ? mb_strtolower($name) : null,
                'email_bidx' => $c->email_bidx,
                'phone_bidx' => $c->phone_bidx,
            ];
        }

        return $out;
    }

    public function revealsToday(User $actor): int
    {
        return AuditEntry::query()
            ->where('action', self::REVEAL_ACTION)
            ->where('actor_user_id', $actor->id)
            ->where('created_at', '>', now()->subDay())
            ->count();
    }

    /**
     * `roster.list` — once per user per 10 minutes per filter set (masked lists still show names).
     *
     * @param  list<int>  $sectionIds
     */
    public function logList(User $actor, array $sectionIds, int $rows, string $filterKey): void
    {
        $key = 'mp:roster-list:'.CurrentBuilding::id().':'.$actor->id.':'.hash('sha256', $filterKey);

        if (Cache::add($key, 1, now()->addMinutes((int) config('majas.roster.list_audit_minutes')))) {
            sort($sectionIds);
            AuditLogger::record('roster.list', null, data: ['section_ids' => $sectionIds, 'rows' => $rows], actorRole: RosterService::actorRole($actor), actorUserId: (int) $actor->id);
        }
    }

    public static function maskEmail(string $email): string
    {
        return mb_substr($email, 0, 1).'•••@…';
    }

    private static function flagValue(?bool $value): string
    {
        return $value === null ? '' : ($value ? '1' : '0');
    }

    /**
     * @return list<string>
     */
    private function phones(ResidentContact $contact): array
    {
        $json = $this->vault->openField($contact, 'phones');
        $list = $json !== null ? json_decode($json, true) : [];

        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /**
     * @throws RosterActionFailed cap
     */
    private function assertCap(User $actor): void
    {
        if ($this->revealsToday($actor) >= (int) config('majas.roster.reveal_per_day')) {
            throw new RosterActionFailed('cap');
        }
    }
}
