<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Audit\AuditLogger;
use App\Enums\BuildingRoleType;
use App\Enums\BuildingStatus;
use App\Enums\PlatformRole;
use App\Models\Building;
use App\Models\BuildingRole;
use App\Models\User;
use App\Notifications\CoreGateChanged;
use Illuminate\Support\Facades\Notification;

/**
 * Audits and alerts gate L-core transitions (PLANNING §5): a regression (open → closed) stops new redemptions,
 * prints and heating/outage writes, alerts building_admin + platform admins and is audited; opening is audited.
 * Call after any change to buildings.settings or building_privacy_notices (context = the building).
 */
final class CoreGateMonitor
{
    public static function afterChange(Building $building, CoreGateStatus $before): CoreGateStatus
    {
        $building->refresh();
        $after = CoreGate::status($building);

        if ($before->open === $after->open) {
            return $after;
        }

        AuditLogger::record(
            action: $after->open ? 'gate.core.opened' : 'gate.core.closed',
            subject: $building,
            data: ['missing' => $after->missing],
        );

        // "Māja atvērta pieteikšanai" — the first opening date is kept for display (ROUND2-SPEC §3.3; not a gate input).
        if ($after->open && $building->settings->openingCompletedAt === null) {
            $changes = ['opening_completed_at' => now()->setTimezone('Europe/Riga')->toDateString()];

            // FIRSTRUN-001: L-core already covers heating + outages (DPA scope and notice), but both modules stayed off
            // after the wizard and nothing said so — residents of an "open" house saw no heating or water at all. At
            // the FIRST opening they are switched on unless the admin already chose one of them; a draft house becomes
            // active. Both audited; switching off later stays in Iestatījumi › Moduļi.
            $modules = $building->settings->modules;
            $defaults = ! $modules['heating'] && ! $modules['outages'];
            if ($defaults) {
                $changes['modules'] = ['heating' => true, 'outages' => true];
            }

            $building->forceFill([
                'settings' => $building->settings->with($changes),
                'status' => $building->status === BuildingStatus::Draft ? BuildingStatus::Active : $building->status,
            ])->save();

            AuditLogger::record(
                action: 'opening.first_open',
                subject: $building,
                data: ['modules_default' => $defaults, 'status' => $building->status->value],
            );
        }

        $adminIds = BuildingRole::query()->current()->where('role', BuildingRoleType::BuildingAdmin->value)->pluck('user_id');
        $recipients = User::query()
            ->whereNull('anonymised_at')
            ->whereNotNull('email')
            ->where(fn ($q) => $q->whereIn('id', $adminIds)->orWhere('platform_role', PlatformRole::PlatformAdmin->value))
            ->get();

        Notification::send($recipients, new CoreGateChanged($building->name, $after->open, $after->missing));

        return $after;
    }
}
