<?php

declare(strict_types=1);

namespace App\Filament\Manage\Widgets;

use App\Gdpr\CoreGate;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Tenancy\CurrentBuilding;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Aggregate tiles for the building (counts only): apartments, apartments with an approved member, pending
 * requests, gate L-core status.
 */
final class BuildingOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $building = CurrentBuilding::get();
        $apartments = Apartment::query()->active()->count();
        $joined = ApartmentMembership::query()->approved()->distinct()->count('apartment_id');
        $pending = ApartmentMembership::query()->pending()->count();
        $gate = CoreGate::status($building);
        $pct = $apartments > 0 ? (int) floor($joined * 100 / $apartments) : 0;

        return [
            Stat::make(__('admin.overview.apartments'), (string) $apartments),
            Stat::make(__('admin.overview.joined'), $joined.' ('.$pct.'%)')->description(__('admin.overview.joined_help')),
            Stat::make(__('admin.overview.pending'), (string) $pending)->color($pending > 0 ? 'warning' : 'gray'),
            Stat::make(__('admin.gate.core'), $gate->open ? __('admin.gate.open') : __('admin.gate.closed'))
                ->description($gate->open ? null : implode(', ', $gate->missingLabels()))
                ->color($gate->open ? 'success' : 'warning'),
        ];
    }
}
