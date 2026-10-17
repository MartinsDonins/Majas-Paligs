<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Apartment;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

final class ApartmentPolicy
{
    use ResolvesBuildingRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user);
    }

    public function view(User $user, Apartment $apartment): bool
    {
        return $this->isAdmin($user) || $this->isElderOf($user, $apartment->section_id);
    }

    public function create(User $user): bool
    {
        return false; // structure comes from the generator (platform)
    }

    public function update(User $user, Apartment $apartment): bool
    {
        return $this->isAdmin($user);
    }

    public function merge(User $user, Apartment $apartment): bool
    {
        return $this->isAdmin($user) && ! $apartment->isArchived();
    }

    public function delete(User $user, Apartment $apartment): bool
    {
        return false;
    }
}
