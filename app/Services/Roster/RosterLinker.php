<?php

declare(strict_types=1);

namespace App\Services\Roster;

use App\Audit\AuditLogger;
use App\Gdpr\RosterGate;
use App\Models\ApartmentMembership;
use App\Models\Building;
use App\Models\ResidentContact;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\UserScopedQuery;
use Throwable;

/**
 * Links a roster contact to a platform account (ROUND2-SPEC §5.5): only when the account's VERIFIED e-mail equals the
 * contact's e-mail (blind index) AND the account holds an APPROVED membership of that same apartment — never across
 * apartments, never by name. Audited `roster.link` with the linked user as data subject (so the reveal / export rows
 * about the contact appear in their "Kas skatīja manus datus").
 *
 * Hooks: contact saved (RosterService), membership approved (MembershipService), e-mail verified (LoginCompleter).
 * A closed gate L-R never blocks those flows — linking simply waits.
 */
final class RosterLinker
{
    public function __construct(private readonly ContactVault $vault) {}

    /**
     * After a contact was created / updated with $email.
     */
    public function linkContact(ResidentContact $contact, ?string $email, ?User $actor = null): void
    {
        if ($email === null || $contact->user_id !== null) {
            return;
        }

        $normalized = ContactVault::normalizeEmail($email);
        $userIds = ApartmentMembership::query()->approved()->where('apartment_id', $contact->apartment_id)->pluck('user_id');
        $matches = User::query()->whereIn('id', $userIds)->whereNotNull('email_verified_at')->whereNull('anonymised_at')
            ->get(['id', 'email'])
            ->filter(static fn (User $u): bool => $u->email !== null && ContactVault::normalizeEmail($u->email) === $normalized);

        if ($matches->count() === 1) {
            $this->link($contact, (int) $matches->first()->id, $actor);
        }
    }

    /**
     * After a membership was approved: link the member's contact rows of THAT apartment.
     */
    public function afterMembershipApproved(ApartmentMembership $membership): void
    {
        try {
            if (! RosterGate::isOpen()) {
                return;
            }

            $user = User::query()->find($membership->user_id);
            if ($user === null || $user->email === null || $user->email_verified_at === null || $user->isTombstone()) {
                return;
            }

            $bidx = $this->vault->bidx(CurrentBuilding::id(), 'email', ContactVault::normalizeEmail($user->email));
            $contacts = ResidentContact::query()->where('apartment_id', $membership->apartment_id)->whereNull('user_id')->withEmailBidx($bidx)->get();

            foreach ($contacts as $contact) {
                $this->link($contact, (int) $user->id, null);
            }
        } catch (Throwable $e) {
            report($e); // linking is a convenience; it never breaks an approval
        }
    }

    /**
     * After the account's e-mail was verified (first e-mail login): every building with an approved membership.
     */
    public function afterEmailVerified(User $user): void
    {
        try {
            UserScopedQuery::for($user)->eachBuilding(function (Building $building) use ($user): void {
                foreach (ApartmentMembership::query()->approved()->where('user_id', $user->id)->get() as $membership) {
                    $this->afterMembershipApproved($membership);
                }
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function link(ResidentContact $contact, int $userId, ?User $actor): void
    {
        if (! RosterGate::isOpen()) {
            return;
        }

        $contact->forceFill(['user_id' => $userId])->save();
        AuditLogger::record('roster.link', $contact, [$userId], data: ['apartment_id' => (int) $contact->apartment_id], actorRole: $actor !== null ? RosterService::actorRole($actor) : 'system', actorUserId: $actor !== null ? (int) $actor->id : null);
    }
}
