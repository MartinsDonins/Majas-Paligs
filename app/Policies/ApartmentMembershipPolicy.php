<?php

declare(strict_types=1);

namespace App\Policies;

use App\Gdpr\CoreGate;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

/**
 * MembershipPolicy (MVP-SPEC A.9): approve/reject = section_elder of THAT section or building_admin, never
 * self-approval; create only with gate L-core open.
 */
final class ApartmentMembershipPolicy
{
    use ResolvesBuildingRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    public function view(User $user, ApartmentMembership $membership): bool
    {
        return $membership->user_id === $user->id
            || $this->isAdmin($user)
            || $this->isElderOf($user, $this->sectionOf($membership));
    }

    public function create(User $user): bool
    {
        $b = $this->building();

        return $b !== null && CoreGate::status($b)->open;
    }

    public function approve(User $user, ApartmentMembership $membership): bool
    {
        return $membership->isPending() && $this->canDecide($user, $membership);
    }

    public function reject(User $user, ApartmentMembership $membership): bool
    {
        return $membership->isPending() && $this->canDecide($user, $membership);
    }

    public function end(User $user, ApartmentMembership $membership): bool
    {
        if ($membership->user_id === $user->id) {
            return $membership->isApproved() || $membership->isPending();
        }

        return $membership->isApproved() && $this->canDecide($user, $membership);
    }

    public function update(User $user, ApartmentMembership $membership): bool
    {
        return false;
    }

    public function delete(User $user, ApartmentMembership $membership): bool
    {
        return false;
    }

    private function canDecide(User $user, ApartmentMembership $membership): bool
    {
        if ($membership->user_id === $user->id) {
            return false; // never self-approve
        }

        return $this->isAdmin($user) || $this->isElderOf($user, $this->sectionOf($membership));
    }

    private function sectionOf(ApartmentMembership $membership): int
    {
        return (int) Apartment::query()->whereKey($membership->apartment_id)->value('section_id');
    }
}
