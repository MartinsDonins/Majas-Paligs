<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditEntry;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

/**
 * building_admin sees the building log (manage panel); a user sees entries where they are a subject
 * ("Kas skatīja manus datus"); platform_admin reads across tenants only via break-glass.
 */
final class AuditEntryPolicy
{
    use ResolvesBuildingRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, AuditEntry $entry): bool
    {
        if (in_array((int) $user->id, $entry->subject_user_ids, true)) {
            return true;
        }

        $b = $this->building();

        return $b !== null && $entry->building_id === (int) $b->id && $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, AuditEntry $entry): bool
    {
        return false;
    }
}
