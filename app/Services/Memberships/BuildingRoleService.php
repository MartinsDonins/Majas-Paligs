<?php

declare(strict_types=1);

namespace App\Services\Memberships;

use App\Audit\AuditLogger;
use App\Auth\SessionEpoch;
use App\Auth\StepUpService;
use App\Enums\BuildingRoleType;
use App\Models\BuildingRole;
use App\Models\RoleInvitation;
use App\Models\Section;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Grants / revokes delegated building roles (AUTH-004). Roles expire after 12 months unless re-confirmed;
 * elevated roles require an e-mail (step-up). Authorisation is the caller's job (BuildingRolePolicy, or the
 * platform bootstrap via PlatformBuildingContext).
 */
final class BuildingRoleService
{
    /**
     * @param  RoleInvitation|null  $via  the accepted invitation (TeamInvitationService only): the inviter's strong
     *                                    step-up was proven when the invitation was ISSUED, so the check is skipped
     *                                    for exactly that invitation — open, unexpired, same building, role and inviter.
     */
    public function grant(?User $actor, User $user, BuildingRoleType $role, ?int $sectionId = null, ?string $mandateRef = null, ?RoleInvitation $via = null): BuildingRole
    {
        // Granting a role to SOMEONE ELSE is a dangerous action (ADR 0006): a passkey / TOTP of this session, never
        // just a trusted device. The UI asks first (StepUpService::demandStrong); this is the backstop.
        if ($actor !== null && (int) $actor->id !== (int) $user->id && (! app()->runningInConsole() || app()->runningUnitTests())
            && ! $this->authorisedByInvitation($via, $actor, $role, $sectionId)
            && ! app(StepUpService::class)->hasFreshStrongMfa($actor)) {
            throw new AuthorizationException('Granting a role to someone else requires a fresh passkey or TOTP confirmation.');
        }

        if ($user->isTombstone()) {
            throw ValidationException::withMessages(['user_id' => __('admin.role.user_deleted')]);
        }

        $elevated = in_array($role, [BuildingRoleType::BuildingAdmin, BuildingRoleType::SectionElder, BuildingRoleType::ParkingOfficer], true);
        if ($elevated && $user->email === null) {
            throw ValidationException::withMessages(['user_id' => __('admin.role.needs_email')]);
        }

        if ($role === BuildingRoleType::SectionElder) {
            if ($sectionId === null || ! Section::query()->whereKey($sectionId)->exists()) {
                throw ValidationException::withMessages(['section_id' => __('admin.role.needs_section')]);
            }
        } else {
            $sectionId = null;
        }

        $existing = BuildingRole::query()->current()
            ->where('user_id', $user->id)->where('role', $role->value)
            ->where(fn ($q) => $sectionId === null ? $q->whereNull('section_id') : $q->where('section_id', $sectionId))
            ->first();

        if ($existing !== null) {
            // Re-confirmation extends the mandate by 12 months.
            $existing->forceFill(['expires_at' => now()->addMonths((int) config('majas.roles.valid_months', 12)), 'mandate_ref' => $mandateRef ?? $existing->mandate_ref])->save();
            AuditLogger::record('role.reconfirm', $existing, [(int) $user->id], data: ['role' => $role->value], actorUserId: $actor?->id);

            return $existing;
        }

        $grant = BuildingRole::query()->create([
            'user_id' => $user->id,
            'role' => $role,
            'section_id' => $sectionId,
            'granted_by' => $actor?->id,
            'granted_at' => now(),
            'expires_at' => now()->addMonths((int) config('majas.roles.valid_months', 12)),
            'mandate_ref' => $mandateRef,
        ]);

        AuditLogger::record('role.grant', $grant, [(int) $user->id], data: ['role' => $role->value, 'section_id' => $sectionId, 'building_id' => CurrentBuilding::id()], actorUserId: $actor?->id);

        return $grant;
    }

    /**
     * @param  bool  $platformOverride  only the platform panel (platform_admin) may remove the LAST building_admin
     *
     * @throws ValidationException last_building_admin
     */
    public function revoke(?User $actor, BuildingRole $role, bool $platformOverride = false): void
    {
        // Last-admin guard (ROUND2-SPEC §2.2 item 4): a house is never left without an administrator by its own team.
        // Hand over first: invite → the new admin accepts → "Atteikties no savas lomas".
        if ($role->role === BuildingRoleType::BuildingAdmin && $role->revoked_at === null && ! $platformOverride
            && $this->isLastAdmin($role)) {
            throw ValidationException::withMessages(['role' => __('admin.team.error.last_building_admin')]);
        }

        $role->forceFill(['revoked_at' => now()])->save();
        AuditLogger::record('role.revoke', $role, [(int) $role->user_id], data: ['role' => $role->role->value], actorUserId: $actor?->id);

        // A revoked role must not survive in an open session (step-up freshness, panel tabs).
        $holder = User::query()->find($role->user_id);
        if ($holder !== null) {
            SessionEpoch::bump($holder);
        }
    }

    /**
     * "Pagarināt uz 12 mēnešiem" from the Komanda page: extends a current role (mfa step-up — the holder already has
     * the role, nothing new is granted, so the strong bar of grant() does not apply).
     */
    public function reconfirm(User $actor, BuildingRole $role): BuildingRole
    {
        if ((! app()->runningInConsole() || app()->runningUnitTests()) && ! app(StepUpService::class)->hasFreshMfa($actor)) {
            throw new AuthorizationException('Re-confirming a role requires a fresh second factor.');
        }

        if ($role->revoked_at !== null) {
            throw ValidationException::withMessages(['role' => __('admin.team.error.not_open')]);
        }

        $role->forceFill(['expires_at' => now()->addMonths((int) config('majas.roles.valid_months', 12))])->save();
        AuditLogger::record('role.reconfirm', $role, [(int) $role->user_id], data: ['role' => $role->role->value], actorUserId: (int) $actor->id);

        return $role;
    }

    /**
     * True when $role is the only current building_admin role of its building (current context).
     */
    public function isLastAdmin(BuildingRole $role): bool
    {
        return ! BuildingRole::query()->current()
            ->where('role', BuildingRoleType::BuildingAdmin->value)
            ->whereKeyNot($role->getKey())
            ->exists();
    }

    /**
     * Backstop for the invitation path: the strong check is skipped ONLY for a still-open, unexpired invitation of the
     * current building issued by this actor for this role (and stairwell).
     */
    private function authorisedByInvitation(?RoleInvitation $via, User $actor, BuildingRoleType $role, ?int $sectionId): bool
    {
        if ($via === null || ! $via->exists || ! $via->isUsable()) {
            return false;
        }

        $fresh = RoleInvitation::query()->whereKey($via->getKey())->first();

        return $fresh !== null && $fresh->isUsable()
            && (int) $fresh->building_id === CurrentBuilding::id()
            && (int) $fresh->invited_by === (int) $actor->id
            && $fresh->role === $role
            && hash_equals((string) $fresh->token_hash, (string) $via->token_hash)
            && ($role !== BuildingRoleType::SectionElder || in_array((int) $sectionId, $fresh->section_ids, true));
    }
}
