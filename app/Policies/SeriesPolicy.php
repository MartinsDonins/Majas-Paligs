<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Series catalogue and other platform data: platform_admin only.
 */
final class SeriesPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function view(User $user, mixed $model = null): bool
    {
        return $user->isPlatformAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function update(User $user, mixed $model = null): bool
    {
        return $user->isPlatformAdmin();
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return $user->isPlatformAdmin();
    }
}
