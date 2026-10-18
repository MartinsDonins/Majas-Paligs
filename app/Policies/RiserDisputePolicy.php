<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Riser;
use App\Models\RiserDispute;
use App\Models\RiserLink;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

/**
 * Riser disputes ("Mans radiators nav šajā stāvadā", HEAT-007): an approved member may dispute a riser linked to
 * their own apartment; building_admin resolves or rejects. Elders see disputes of their own stairwell.
 */
final class RiserDisputePolicy
{
    use ResolvesBuildingRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    public function view(User $user, RiserDispute $dispute): bool
    {
        return $this->isAdmin($user) || $this->isElderOf($user, (int) $dispute->riser->section_id);
    }

    public function open(User $user, Riser $riser): bool
    {
        $b = $this->building();
        $membership = $b !== null && ! $user->isTombstone() ? $user->approvedMembershipFor($b) : null;

        return $membership !== null && RiserLink::query()
            ->where('riser_id', $riser->id)
            ->where('apartment_id', $membership->apartment_id)
            ->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function resolve(User $user, RiserDispute $dispute): bool
    {
        return $this->isAdmin($user) && $dispute->status->value === 'open';
    }

    public function update(User $user, RiserDispute $dispute): bool
    {
        return false;
    }

    public function delete(User $user, RiserDispute $dispute): bool
    {
        return false;
    }
}
