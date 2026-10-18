<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Riser;
use App\Models\RiserCheck;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

/**
 * RiserCheckPolicy (MVP-SPEC B.5): checks ("Pārbaudīts") are recorded by building_admin.
 */
final class RiserCheckPolicy
{
    use ResolvesBuildingRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, RiserCheck $check): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function record(User $user, Riser $riser): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, RiserCheck $check): bool
    {
        return false;
    }

    public function delete(User $user, RiserCheck $check): bool
    {
        return false;
    }
}
