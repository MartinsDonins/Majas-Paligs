<?php

declare(strict_types=1);

namespace App\Services\Memberships;

use App\Audit\AuditLogger;
use App\Auth\SessionEpoch;
use App\Enums\BuildingRoleType;
use App\Models\BuildingRole;
use App\Models\Section;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Illuminate\Validation\ValidationException;

/**
 * Grants / revokes delegated building roles (AUTH-004). Roles expire after 12 months unless re-confirmed;
 * elevated roles require an e-mail (step-up). Authorisation is the caller's job (BuildingRolePolicy, or the
 * platform bootstrap via PlatformBuildingContext).
 */
final class BuildingRoleService
{
    public function grant(?User $actor, User $user, BuildingRoleType $role, ?int $sectionId = null, ?string $mandateRef = null): BuildingRole
    {
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

    public function revoke(?User $actor, BuildingRole $role): void
    {
        $role->forceFill(['revoked_at' => now()])->save();
        AuditLogger::record('role.revoke', $role, [(int) $role->user_id], data: ['role' => $role->role->value], actorUserId: $actor?->id);

        // A revoked role must not survive in an open session (step-up freshness, panel tabs).
        $holder = User::query()->find($role->user_id);
        if ($holder !== null) {
            SessionEpoch::bump($holder);
        }
    }
}
