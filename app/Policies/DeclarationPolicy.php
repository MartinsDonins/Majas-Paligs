<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Apartment;
use App\Models\ApartmentParkingDeclaration;
use App\Models\User;
use App\Policies\Concerns\ChecksParkingGate;

/**
 * DeclarationPolicy (MVP-SPEC C.4): declare for an apartment ("mums nav auto" / "mūsu auto") — approved member of
 * THAT apartment at L2 with the module on; parking_officer / building_admin see and confirm declarations at L1.
 */
final class DeclarationPolicy
{
    use ChecksParkingGate;

    public function before(User $user, string $ability): ?bool
    {
        return $this->parkingLevel() < 1 || $user->isTombstone() ? false : null;
    }

    public function declare(User $user, Apartment $apartment): bool
    {
        return $this->residentParking() && $this->canManageVehiclesOf($user, (int) $apartment->id);
    }

    public function viewAny(User $user): bool
    {
        return $this->isParkingStaff($user);
    }

    public function view(User $user, ApartmentParkingDeclaration $declaration): bool
    {
        return $this->isParkingStaff($user)
            || ($this->residentParking() && $this->approvedIn($user, (int) $declaration->apartment_id) !== null);
    }

    public function update(User $user, ApartmentParkingDeclaration $declaration): bool
    {
        return $this->isParkingStaff($user);
    }

    public function delete(User $user, ApartmentParkingDeclaration $declaration): bool
    {
        return false;
    }
}
