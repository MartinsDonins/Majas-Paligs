<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RoleInvitation;
use App\Models\User;
use App\Policies\Concerns\ResolvesBuildingRoles;

/**
 * Team invitations (ROUND2-SPEC §2.4): every manage role of the building sees the list (others than building_admin see
 * e-mail hints only — the page decides what to render); issuing, resending and revoking are building_admin. Accepting
 * is not a policy on the invitee: TeamInvitationService checks the token and the e-mail HMAC.
 */
final class RoleInvitationPolicy
{
    use ResolvesBuildingRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isElder($user) || $this->isParkingOfficer($user);
    }

    public function view(User $user, RoleInvitation $invitation): bool
    {
        return $this->viewAny($user);
    }

    public function viewEmail(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function resend(User $user, RoleInvitation $invitation): bool
    {
        return $this->isAdmin($user) && $invitation->accepted_at === null && $invitation->revoked_at === null;
    }

    public function revoke(User $user, RoleInvitation $invitation): bool
    {
        return $this->isAdmin($user) && $invitation->accepted_at === null && $invitation->revoked_at === null;
    }
}
