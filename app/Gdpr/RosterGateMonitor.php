<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Audit\AuditLogger;
use App\Enums\BuildingRoleType;
use App\Enums\PlatformRole;
use App\Models\Building;
use App\Models\BuildingRole;
use App\Models\User;
use App\Notifications\RosterGateChanged;
use Illuminate\Support\Facades\Notification;

/**
 * Audits and alerts gate L-R transitions (ROUND2-SPEC §3.1): a regression (open → closed) is audited as
 * `gate.roster.closed` and alerts building_admin + platform admins — the roster data stays but the UI stops showing
 * it until the gate is open again; opening is audited as `gate.roster.opened`.
 * Call after any change to buildings.settings or building_privacy_notices, next to CoreGateMonitor.
 */
final class RosterGateMonitor
{
    public static function afterChange(Building $building, RosterGateStatus $before): RosterGateStatus
    {
        $building->refresh();
        $after = RosterGate::status($building);

        if ($before->open === $after->open) {
            return $after;
        }

        AuditLogger::record(
            action: $after->open ? 'gate.roster.opened' : 'gate.roster.closed',
            subject: $building,
            data: ['missing' => $after->missing],
        );

        $adminIds = BuildingRole::query()->current()->where('role', BuildingRoleType::BuildingAdmin->value)->pluck('user_id');
        $recipients = User::query()
            ->whereNull('anonymised_at')
            ->whereNotNull('email')
            ->where(fn ($q) => $q->whereIn('id', $adminIds)->orWhere('platform_role', PlatformRole::PlatformAdmin->value))
            ->get();

        Notification::send($recipients, new RosterGateChanged($building->name, $after->open, $after->missing));

        return $after;
    }
}
