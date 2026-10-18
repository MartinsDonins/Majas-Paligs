<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksParkingGate;

/**
 * PermitImportPolicy (MVP-SPEC C.4, gate name `parking-import`): the permit issuer's list import — building_admin or
 * parking_officer, only at gate L1 (controller named + DPA covering parking signed). Below L1 the page is unavailable.
 */
final class PermitImportPolicy
{
    use ChecksParkingGate;

    public function import(User $user): bool
    {
        return $this->parkingLevel() >= 1 && ! $user->isTombstone() && $this->isParkingStaff($user);
    }
}
