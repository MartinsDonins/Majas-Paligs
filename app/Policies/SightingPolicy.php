<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sighting;
use App\Models\User;
use App\Policies\Concerns\ChecksParkingGate;

/**
 * SightingPolicy (MVP-SPEC C.4): create / dispute — approved member at L2 with the module on; view — the reporter
 * (own reports, neutral state only) or parking_officer / building_admin (L1); photo + handle (dismiss / resolve) —
 * parking_officer / building_admin only.
 */
final class SightingPolicy
{
    use ChecksParkingGate;

    public function before(User $user, string $ability): ?bool
    {
        return $this->parkingLevel() < 1 || $user->isTombstone() ? false : null;
    }

    public function create(User $user): bool
    {
        return $this->residentParking() && $this->isApprovedMember($user);
    }

    public function dispute(User $user): bool
    {
        return $this->create($user);
    }

    public function viewAny(User $user): bool
    {
        return $this->isParkingStaff($user);
    }

    public function view(User $user, Sighting $sighting): bool
    {
        return $this->isParkingStaff($user)
            || ($this->residentParking() && (int) $sighting->reporter_user_id === (int) $user->id);
    }

    public function photo(User $user, Sighting $sighting): bool
    {
        return $this->isParkingStaff($user);
    }

    public function update(User $user, Sighting $sighting): bool
    {
        return $this->isParkingStaff($user);
    }

    public function delete(User $user, Sighting $sighting): bool
    {
        return false;
    }
}
