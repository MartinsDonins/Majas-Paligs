<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\StepUpService;
use App\Enums\MembershipRole;
use App\Models\Apartment;
use App\Models\User;
use App\Models\Vehicle;
use App\Policies\Concerns\ChecksParkingGate;

/**
 * VehiclePolicy (MVP-SPEC C.4). before(): nothing below gate L1.
 * - viewAny / create / verify (activate, reject) / delete any: parking_officer or building_admin (L1).
 * - viewOwn / declare / update / delete own: approved owner / tenant / family of THAT apartment at L2 with the
 *   module on — a tenant only for the vehicles they declared themselves.
 * - reveal (plate ↔ apartment): parking_officer / building_admin with a fresh step-up.
 */
final class VehiclePolicy
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

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $this->isParkingStaff($user) || $this->ownsVehicle($user, $vehicle);
    }

    public function create(User $user): bool
    {
        return $this->isParkingStaff($user);
    }

    public function viewOwn(User $user, Apartment $apartment): bool
    {
        return $this->residentParking() && $this->approvedIn($user, (int) $apartment->id) !== null;
    }

    public function declare(User $user, Apartment $apartment): bool
    {
        return $this->residentParking() && $this->canManageVehiclesOf($user, (int) $apartment->id);
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $this->isParkingStaff($user) || $this->ownsVehicle($user, $vehicle);
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $this->isParkingStaff($user) || $this->ownsVehicle($user, $vehicle);
    }

    public function verify(User $user, Vehicle $vehicle): bool
    {
        return $this->isParkingStaff($user);
    }

    public function reveal(User $user, ?Vehicle $vehicle = null): bool
    {
        return $this->isParkingStaff($user) && app(StepUpService::class)->hasFresh($user);
    }

    private function ownsVehicle(User $user, Vehicle $vehicle): bool
    {
        if (! $this->residentParking()) {
            return false;
        }

        $m = $this->approvedIn($user, (int) $vehicle->apartment_id);
        if ($m === null || $m->role === MembershipRole::Contact) {
            return false;
        }

        return $m->role !== MembershipRole::Tenant || (int) $vehicle->declared_by_user_id === (int) $user->id;
    }
}
