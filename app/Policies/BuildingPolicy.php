<?php

declare(strict_types=1);

namespace App\Policies;

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

    private function inContext(Building $building): bool
    {
        return CurrentBuilding::has() && CurrentBuilding::id() === (int) $building->id;
    }
}
