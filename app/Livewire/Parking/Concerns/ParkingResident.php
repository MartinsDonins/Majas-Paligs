<?php

declare(strict_types=1);

namespace App\Livewire\Parking\Concerns;

use App\Models\Apartment;
use App\Models\User;
use App\Tenancy\CurrentBuilding;

/**
 * Shared helpers of the resident parking components: the signed-in user and the apartment of their APPROVED
 * membership in the current building (never a client-supplied id). Pages mount only behind `module:parking`
 * (modules.parking ON + gate L2), re-checked by the persistent middleware on every Livewire update.
 */
trait ParkingResident
{
    protected function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    protected function myApartment(): ?Apartment
    {
        $membership = $this->user()->approvedMembershipFor(CurrentBuilding::get());

        return $membership !== null ? Apartment::query()->with('section')->find($membership->apartment_id) : null;
    }

    /**
     * Membership of any status (pending members see read-only parking information).
     */
    protected function anyApartment(): ?Apartment
    {
        $membership = $this->user()->membershipFor(CurrentBuilding::get());

        return $membership !== null ? Apartment::query()->with('section')->find($membership->apartment_id) : null;
    }
}
