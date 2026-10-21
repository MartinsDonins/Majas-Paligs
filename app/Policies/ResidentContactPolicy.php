<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\StepUpService;
use App\Gdpr\RosterGate;
use App\Models\Apartment;
use App\Models\ResidentContact;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

/**
 * ResidentContactPolicy (ADMIN-007, ROUND2-SPEC §5.4). before(): nothing while gate L-R is closed (except `openPage`,
 * so admins and elders see WHY the roster is closed), nothing for tombstones.
 *
 * | ability                                   | building_admin | section_elder        | everyone else |
 * |-------------------------------------------|----------------|----------------------|---------------|
 * | viewAny / view (masked)                   | all stairwells | own stairwells only  | ✖             |
 * | reveal (unmasked, mfa step-up)            | ✔              | own stairwells       | ✖             |
 * | create / update / delete / reviewed / out | ✔              | own stairwells       | ✖             |
 * | import                                    | ✔              | ✖ (round 2)          | ✖             |
 * | export (strong step-up)                   | ✔              | ✖                    | ✖             |
 *
 * parking_officer, volunteer, residents, the manager and platform_admin (outside BreakGlass) never pass.
 */
final class ResidentContactPolicy
{
    use ResolvesBuildingRoles;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->isTombstone()) {
            return false;
        }

        if ($ability === 'openPage') {
            return null;
        }

        return RosterGate::isOpen() ? null : false;
    }

    /**
     * May open the roster pages at all (role only — below the gate the page shows the closed card).
     */
    public function openPage(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    public function view(User $user, ResidentContact $contact): bool
    {
        return $this->mayTouchApartment($user, (int) $contact->apartment_id);
    }

    public function create(User $user, ?Apartment $apartment = null): bool
    {
        if ($apartment === null) {
            return $this->isAdmin($user) || $this->isElder($user);
        }

        return $this->isAdmin($user) || $this->isElderOf($user, (int) $apartment->section_id);
    }

    public function update(User $user, ResidentContact $contact): bool
    {
        return $this->mayTouchApartment($user, (int) $contact->apartment_id);
    }

    public function delete(User $user, ResidentContact $contact): bool
    {
        return $this->mayTouchApartment($user, (int) $contact->apartment_id);
    }

    /**
     * Unmasked phones / e-mails of an apartment (or of one contact): a fresh SECOND factor, whatever the role.
     */
    public function reveal(User $user, Apartment|ResidentContact $target): bool
    {
        $apartmentId = $target instanceof Apartment ? (int) $target->id : (int) $target->apartment_id;

        return $this->mayTouchApartment($user, $apartmentId) && app(StepUpService::class)->hasFreshMfa($user);
    }

    public function revealApartment(User $user, Apartment $apartment): bool
    {
        return $this->mayTouchApartment($user, (int) $apartment->id);
    }

    public function import(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function export(User $user): bool
    {
        return $this->isAdmin($user) && app(StepUpService::class)->hasFreshStrongMfa($user);
    }

    private function mayTouchApartment(User $user, int $apartmentId): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $sectionId = Apartment::query()->whereKey($apartmentId)->value('section_id');

        return $sectionId !== null && $this->isElderOf($user, (int) $sectionId);
    }
}
