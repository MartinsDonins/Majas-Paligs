<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BuildingRole;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

/**
 * Roles are granted by building_admin (platform_admin bootstraps the first admin via the platform panel or
 * `php artisan majas:grant-role`).
 */
final class BuildingRolePolicy
{
    use ResolvesBuildingRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, BuildingRole $role): bool
    {
        return $role->user_id === $user->id || $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function revoke(User $user, BuildingRole $role): bool
    {
        return $this->isAdmin($user) && $role->revoked_at === null;
    }

    public function update(User $user, BuildingRole $role): bool
    {
        return $this->isAdmin($user) && $role->revoked_at === null;
    }

    public function delete(User $user, BuildingRole $role): bool
    {
        return false;
    }
}
