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
 * Audits roster gate transitions (ROUND2-SPEC §3.1): opening → `gate.roster.opened`; a regression (DPA scope or notice
 * lost) → `gate.roster.closed` and an alert to building_admin(s) + platform admins. The rows stay stored but the UI
 * and every policy refuse them until the gate is open again ("Saraksts slēgts: …"). Call after any change to
 * buildings.settings or building_privacy_notices (context = the building), next to CoreGateMonitor::afterChange.
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

        AuditLogger::record($after->open ? 'gate.roster.opened' : 'gate.roster.closed', $building, data: ['missing' => $after->missing]);

        if ($after->open) {
            return $after;
        }

        $adminIds = BuildingRole::query()->current()->where('role', BuildingRoleType::BuildingAdmin->value)->pluck('user_id');
        $recipients = User::query()
            ->whereNull('anonymised_at')
            ->whereNotNull('email')
            ->where(fn ($q) => $q->whereIn('id', $adminIds)->orWhere('platform_role', PlatformRole::PlatformAdmin->value))
            ->get();

        Notification::send($recipients, new RosterGateChanged($building->name, $after->missing));

        return $after;
    }
}
