<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\MembershipRole;
use App\Gdpr\ParkingGate;
use App\Models\ApartmentMembership;
use App\Models\User;

/**
 * Parking policy helpers (MVP-SPEC C.0 / C.4): every parking policy denies in before() below the level its abilities
 * need — staff (parking_officer / building_admin) abilities need L1, resident abilities need L2 AND modules.parking.
 */
trait ChecksParkingGate
{
    use ResolvesBuildingRoles;

    protected function parkingLevel(): int
    {
        $status = ParkingGate::current();

        return $status === null ? 0 : $status->level;
    }

    /**
     * Resident-facing parking is live: L2 + module switched on.
     */
    protected function residentParking(): bool
    {
        return ParkingGate::current()?->residentEnabled() ?? false;
    }

    /**
     * The user's APPROVED membership of that apartment (not pending), or null.
     */
    protected function approvedIn(User $user, int $apartmentId): ?ApartmentMembership
    {
        if ($user->isTombstone()) {
            return null;
        }

        return ApartmentMembership::query()->approved()
            ->where('user_id', $user->id)
            ->where('apartment_id', $apartmentId)
            ->first();
    }

    /**
     * Owner / tenant / family may manage the apartment's vehicles (a "contact" may not).
     */
    protected function canManageVehiclesOf(User $user, int $apartmentId): bool
    {
        $m = $this->approvedIn($user, $apartmentId);

        return $m !== null && in_array($m->role, [MembershipRole::Owner, MembershipRole::Tenant, MembershipRole::Family], true);
    }
}
