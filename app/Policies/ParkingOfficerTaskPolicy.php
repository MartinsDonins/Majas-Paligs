<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ParkingOfficerTask;
use App\Models\User;
use App\Policies\Concerns\ChecksParkingGate;

/**
 * Officer queue: parking_officer / building_admin only, gate L1.
 */
final class ParkingOfficerTaskPolicy
{
    use ChecksParkingGate;

    public function before(User $user, string $ability): ?bool
    {
        return $this->parkingLevel() < 1 || $user->isTombstone() ? false : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->isParkingStaff($user);
    }

    public function view(User $user, ParkingOfficerTask $task): bool
    {
        return $this->isParkingStaff($user);
    }

    public function update(User $user, ParkingOfficerTask $task): bool
    {
        return $this->isParkingStaff($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, ParkingOfficerTask $task): bool
    {
        return false;
    }
}
