<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Platform user administration (lock, platform role): platform_admin only, never on oneself for role changes.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id || $user->isPlatformAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isPlatformAdmin() && ! $model->isTombstone();
    }

    public function changePlatformRole(User $user, User $model): bool
    {
        return $user->isPlatformAdmin() && $user->id !== $model->id && ! $model->isTombstone();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, User $model): bool
    {
        return false; // deletion = self-service tombstone (AccountEraser)
    }
}
