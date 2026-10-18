<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Apartment;
use App\Models\Permit;
use App\Models\User;
use App\Policies\Concerns\ChecksParkingGate;

/**
 * PermitPolicy (MVP-SPEC C.4): manage (issue, replace, return / lost / revoke) — parking_officer or
 * building_admin at gate L1; viewOwn — approved members of that apartment at L2 with the module on.
 */
final class PermitPolicy
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

    public function view(User $user, Permit $permit): bool
    {
        return $this->isParkingStaff($user)
            || ($this->residentParking() && $this->approvedIn($user, (int) $permit->apartment_id) !== null);
    }

    public function viewOwn(User $user, Apartment $apartment): bool
    {
        return $this->residentParking() && $this->approvedIn($user, (int) $apartment->id) !== null;
    }

    public function create(User $user): bool
    {
        return $this->isParkingStaff($user);
    }

    public function update(User $user, Permit $permit): bool
    {
        return $this->isParkingStaff($user);
    }

    public function delete(User $user, Permit $permit): bool
    {
        return false;
    }
}
