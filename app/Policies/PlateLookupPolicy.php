<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlateLookup;
use App\Models\User;
use App\Policies\Concerns\ChecksParkingGate;

/**
 * PlateLookupPolicy (MVP-SPEC C.4): lookup — approved members only (pending members cannot), gate L2 + module on.
 * The log itself (viewAny) — building_admin / parking_officer (L1) through the audit view only.
 */
final class PlateLookupPolicy
{
    use ChecksParkingGate;

    public function before(User $user, string $ability): ?bool
    {
        return $this->parkingLevel() < 1 || $user->isTombstone() ? false : null;
    }

    public function lookup(User $user): bool
    {
        return $this->residentParking() && $this->isApprovedMember($user);
    }

    public function create(User $user): bool
    {
        return $this->lookup($user);
    }

    public function viewAny(User $user): bool
    {
        return $this->isParkingStaff($user);
    }

    public function view(User $user, PlateLookup $lookup): bool
    {
        return $this->isParkingStaff($user);
    }

    public function update(User $user, PlateLookup $lookup): bool
    {
        return false;
    }

    public function delete(User $user, PlateLookup $lookup): bool
    {
        return false;
    }
}
