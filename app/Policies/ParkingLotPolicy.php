<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ParkingLot;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

/**
 * ParkingLotPolicy: lots hold no personal data and are allowed at gate L0 (MVP-SPEC C.0) — parking_officer or
 * building_admin manage them; any member may see lot names (sighting sheet).
 */
final class ParkingLotPolicy
{
    use ResolvesBuildingRoles;

    public function viewAny(User $user): bool
    {
        return $this->isParkingStaff($user);
    }

    public function view(User $user, ParkingLot $lot): bool
    {
        return $this->isMember($user);
    }

    public function create(User $user): bool
    {
        return $this->isParkingStaff($user);
    }

    public function update(User $user, ParkingLot $lot): bool
    {
        return $this->isParkingStaff($user);
    }

    public function delete(User $user, ParkingLot $lot): bool
    {
        return $this->isParkingStaff($user);
    }
}
