<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\PlatformRole;
use App\Jobs\SendBuildingAlert;
use App\Models\Building;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Routes count-only alerts: building alerts (queued, TenantAware) to the building's elders + building_admin,
 * platform alerts to platform admins and PLATFORM_ALERT_EMAIL.
 */
class Alerts
{
    public function building(Building $building, string $limiter, string $window, int $count, int $threshold): void
    {
        SendBuildingAlert::dispatch((int) $building->id, $limiter, $window, $count, $threshold);
    }

    public function platform(string $limiter, string $window, int $count, int $threshold): void
    {
        $alert = new LimiterAlert(null, $limiter, $window, $count, $threshold);

        $admins = User::query()
            ->where('platform_role', PlatformRole::PlatformAdmin->value)
            ->whereNotNull('email')
            ->whereNull('anonymised_at')
            ->get();

        Notification::send($admins, $alert);

        $extra = config('majas.platform_alert_email');
        if (is_string($extra) && $extra !== '') {
            Notification::route('mail', $extra)->notify($alert);
        }
    }
}
