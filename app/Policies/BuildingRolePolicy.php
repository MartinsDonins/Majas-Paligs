<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BuildingRoleType;
use App\Models\BuildingRole;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;
use App\Services\Memberships\BuildingRoleService;

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

    /**
     * building_admin removes any holder; a holder may give up their own role ("Atteikties no savas lomas"). Never the
     * last building_admin of the house (ROUND2-SPEC §2.2 item 4 — hand over first; the platform panel may override).
     */
    public function revoke(User $user, BuildingRole $role): bool
    {
        if ($role->revoked_at !== null || ! ($this->isAdmin($user) || (int) $role->user_id === (int) $user->id)) {
            return false;
        }

        return $role->role !== BuildingRoleType::BuildingAdmin || ! app(BuildingRoleService::class)->isLastAdmin($role);
    }

    /**
     * "Pagarināt uz 12 mēnešiem" (re-confirmation): building_admin.
     */
    public function reconfirm(User $user, BuildingRole $role): bool
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
