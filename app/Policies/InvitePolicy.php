<?php

declare(strict_types=1);

namespace App\Policies;

use App\Gdpr\CoreGate;
use App\Models\ApartmentInvite;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

/**
 * Invite letters (MVP-SPEC A.9): print = building_admin AND gate L-core open; before() denies everything
 * below L-core.
 */
final class InvitePolicy
{
    use ResolvesBuildingRoles;

    public function before(User $user, string $ability): ?bool
    {
        $b = $this->building();

        if ($b === null || ! CoreGate::status($b)->open) {
            return in_array($ability, ['viewAny', 'view'], true) ? null : false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, ApartmentInvite $invite): bool
    {
        return $this->isAdmin($user);
    }

    public function print(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function revoke(User $user, ApartmentInvite $invite): bool
    {
        return $this->isAdmin($user) && $invite->revoked_at === null;
    }
}
