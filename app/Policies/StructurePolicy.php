<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;
use Illuminate\Database\Eloquent\Model;

/**
 * Sections, risers, rooms, land parcels in the manage panel: building_admin edits, elders read.
 * Creating/deleting structure is a platform action (generator inside PlatformBuildingContext).
 */
final class StructurePolicy
{
    use ResolvesBuildingRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
