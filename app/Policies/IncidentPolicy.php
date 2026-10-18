<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BuildingRoleType;
use App\Enums\IncidentOrigin;
use App\Gdpr\CoreGate;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\BuildingRole;
use App\Models\Incident;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;
use App\Services\Outages\ApartmentFootprint;

/**
 * IncidentPolicy (MVP-SPEC B.5).
 * - view: any member (approved or pending) of the building — the detail shows aggregates only.
 * - report ("Nav ūdens"): approved member of that apartment, or elder/volunteer of its stairwell (proxy).
 * - confirm ("Man arī"): approved member whose apartment is covered by the incident's scope; gate L-core open.
 * - createNotice: building_admin any scope; section_elder only for their own stairwell (never building-wide).
 * - resolve: building_admin; elder for notices of their own stairwell.
 * - update (status, manager ref / reply): building_admin.
 */
final class IncidentPolicy
{
    use ResolvesBuildingRoles;

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Incident $incident): bool
    {
        return $this->isMember($user);
    }

    public function report(User $user, Apartment $apartment): bool
    {
        if (! $this->open() || $user->isTombstone()) {
            return false;
        }

        $own = ApartmentMembership::query()->approved()->where('user_id', $user->id)->where('apartment_id', $apartment->id)->exists();

        return $own || $this->isElderOf($user, (int) $apartment->section_id) || $this->isVolunteerOf($user, (int) $apartment->section_id);
    }

    public function confirm(User $user, Incident $incident): bool
    {
        $b = $this->building();
        if (! $this->open() || ! $incident->is_open || $b === null || $user->isTombstone()) {
            return false;
        }

        $membership = $user->approvedMembershipFor($b);
        if ($membership === null) {
            return false;
        }

        $apartment = Apartment::query()->find($membership->apartment_id);

        return $apartment !== null && ApartmentFootprint::of($apartment)->coveredBy($incident);
    }

    public function createNotice(User $user, ?int $sectionId): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $sectionId !== null && $this->isElderOf($user, $sectionId);
    }

    public function resolve(User $user, Incident $incident): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $incident->origin === IncidentOrigin::Notice
            && $incident->section_id !== null
            && $this->isElderOf($user, (int) $incident->section_id);
    }

    public function update(User $user, Incident $incident): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    public function delete(User $user, Incident $incident): bool
    {
        return false;
    }

    private function open(): bool
    {
        $b = $this->building();

        return $b !== null && $b->settings->moduleEnabled('outages') && CoreGate::status($b)->open;
    }

    private function isVolunteerOf(User $user, int $sectionId): bool
    {
        $b = $this->building();

        return $b !== null && $user->rolesIn($b)->contains(
            static fn (BuildingRole $r): bool => $r->role === BuildingRoleType::Volunteer && (int) $r->section_id === $sectionId,
        );
    }
}
