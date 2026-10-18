<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\StepUpService;
use App\Models\Building;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;
use App\Tenancy\CurrentBuilding;

/**
 * view: approved member or active role; update / export: building_admin (MVP-SPEC A.9).
 */
final class BuildingPolicy
{
    use ResolvesBuildingRoles;

    public function view(User $user, Building $building): bool
    {
        return $this->inContext($building) && ($this->isApprovedMember($user) || $this->isMember($user));
    }

    public function update(User $user, Building $building): bool
    {
        return $this->inContext($building) && $this->isAdmin($user);
    }

    public function export(User $user, Building $building): bool
    {
        return $this->inContext($building) && $this->isAdmin($user);
    }

    public function manageNotices(User $user, Building $building): bool
    {
        return $this->inContext($building) && $this->isAdmin($user);
    }

    /**
     * BuildingSettingsPolicy::updateSharing (MVP-SPEC B.5): the manager-sharing flags need building_admin AND a
     * fresh step-up (changes are audited by the settings page).
     */
    public function updateSharing(User $user, Building $building): bool
    {
        return $this->update($user, $building) && app(StepUpService::class)->hasFreshFor($user);
    }

    /**
     * Parking gate fields (dpa_scope ∋ parking, DPIA, mandate, modules.parking, rules, enforcement mode) need
     * building_admin AND a fresh step-up (MVP-SPEC C.0); changes are audited by the settings page.
     */
    public function updateParkingGate(User $user, Building $building): bool
    {
        return $this->update($user, $building) && app(StepUpService::class)->hasFreshFor($user);
    }

    private function inContext(Building $building): bool
    {
        return CurrentBuilding::has() && CurrentBuilding::id() === (int) $building->id;
    }
}
