<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\BuildingRoleType;
use App\Models\Building;
use App\Models\User;
use App\Tenancy\CurrentBuilding;

/**
 * Policy helpers: membership (`$user->membershipFor($building)`) AND role checks in the CURRENT building.
 * Deny by default; tombstoned users never pass.
 */
trait ResolvesBuildingRoles
{
    protected function building(): ?Building
    {
        return CurrentBuilding::find();
    }

    protected function isAdmin(User $user): bool
    {
        $b = $this->building();

        return $b !== null && ! $user->isTombstone() && $user->hasRoleIn($b, BuildingRoleType::BuildingAdmin);
    }

    protected function isElderOf(User $user, int $sectionId): bool
    {
        $b = $this->building();

        return $b !== null && ! $user->isTombstone() && in_array($sectionId, $user->elderSectionIds($b), true);
    }

    protected function isElder(User $user): bool
    {
        $b = $this->building();

        return $b !== null && ! $user->isTombstone() && $user->elderSectionIds($b) !== [];
    }

    protected function isMember(User $user): bool
    {
        $b = $this->building();

        if ($b === null || $user->isTombstone()) {
            return false;
        }

        return $user->membershipFor($b) !== null || $user->rolesIn($b)->isNotEmpty();
    }

    protected function isApprovedMember(User $user): bool
    {
        $b = $this->building();

        return $b !== null && ! $user->isTombstone() && $user->approvedMembershipFor($b) !== null;
    }
}
