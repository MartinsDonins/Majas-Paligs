<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Audit\AuditLogger;
use App\Enums\BuildingRoleType;
use App\Enums\PlatformRole;
use App\Models\Building;
use App\Models\BuildingRole;
use App\Models\User;
use App\Notifications\ParkingGateChanged;
use Illuminate\Support\Facades\Notification;

/**
 * Audits and enforces parking gate transitions (MVP-SPEC C.0): losing a condition drops the level at once — below
 * L2 `modules.parking` is switched OFF automatically (audited), below L1 every parking write is refused by the model
 * guards. Every level change is audited and alerts building_admin + parking_officer + platform admins.
 * Call after any change to buildings.settings or building_privacy_notices (context = the building), next to
 * CoreGateMonitor::afterChange.
 */
final class ParkingGateMonitor
{
    public static function afterChange(Building $building, ParkingGateStatus $before): ParkingGateStatus
    {
        $building->refresh();
        $after = ParkingGate::status($building);
        $switchedOff = false;

        if ($after->moduleOn && ! $after->l2()) {
            $building->forceFill(['settings' => $building->settings->with(['modules' => ['parking' => false]])])->save();
            AuditLogger::record('gate.parking.module_off', $building, data: ['level' => $after->level, 'missing' => [...$after->missingL1, ...$after->missingL2]]);
            $switchedOff = true;
            $after = ParkingGate::status($building->refresh());
        }

        if ($before->level === $after->level && ! $switchedOff) {
            return $after;
        }

        if ($before->level !== $after->level) {
            AuditLogger::record('gate.parking.changed', $building, data: [
                'from' => $before->level, 'to' => $after->level, 'missing' => [...$after->missingL1, ...$after->missingL2],
            ]);
        }

        $roleIds = BuildingRole::query()->current()
            ->whereIn('role', [BuildingRoleType::BuildingAdmin->value, BuildingRoleType::ParkingOfficer->value])
            ->pluck('user_id');
        $recipients = User::query()
            ->whereNull('anonymised_at')
            ->whereNotNull('email')
            ->where(fn ($q) => $q->whereIn('id', $roleIds)->orWhere('platform_role', PlatformRole::PlatformAdmin->value))
            ->get();

        Notification::send($recipients, new ParkingGateChanged(
            $building->name,
            $before->level,
            $after->level,
            $switchedOff,
            $after->level === 0 ? $after->missingL1 : $after->missingL2,
        ));

        return $after;
    }
}
