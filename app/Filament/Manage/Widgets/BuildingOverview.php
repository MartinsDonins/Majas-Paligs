<?php

declare(strict_types=1);

namespace App\Filament\Manage\Widgets;

use App\Models\Apartment;
use App\Models\ApartmentMembership;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Aggregate tiles for the building (counts only): apartments, apartments with an approved member, pending
 * requests. The gate status moved to GateStatusWidget (ROUND2-SPEC §3.7).
 */
final class BuildingOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $apartments = Apartment::query()->active()->count();
        $joined = ApartmentMembership::query()->approved()->distinct()->count('apartment_id');
        $pending = ApartmentMembership::query()->pending()->count();
        $pct = $apartments > 0 ? (int) floor($joined * 100 / $apartments) : 0;

        return [
            Stat::make(__('admin.overview.apartments'), (string) $apartments),
            Stat::make(__('admin.overview.joined'), $joined.' ('.$pct.'%)')->description(__('admin.overview.joined_help')),
            Stat::make(__('admin.overview.pending'), (string) $pending)->color($pending > 0 ? 'warning' : 'gray'),
        ];
    }
}
