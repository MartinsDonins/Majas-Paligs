<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Services\Parking\ParkingAnalytics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Parking analytics (PARK-004): stat tiles — permits per apartment distribution, apartments with 2+ main permits,
 * plates seen on ≥ N days in 60 without registration, declaration coverage per stairwell. Apartment numbers only
 * after the house adopted parking rules (parking_rules_adopted_at). parking_officer / building_admin at gate L1.
 */
final class ParkingAnalyticsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 54;

    protected static ?string $slug = 'parking-analytics';

    protected string $view = 'filament.manage.pages.parking-analytics';

    public int $seenDays = ParkingAnalytics::SEEN_DAYS_DEFAULT;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.parking');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.parking.analytics.title');
    }

    public function getTitle(): string
    {
        return __('admin.parking.analytics.title');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('parking-analytics');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function setSeenDays(int $days): void
    {
        $this->seenDays = max(1, min(60, $days));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return app(ParkingAnalytics::class)->summary($this->seenDays);
    }

    /**
     * @return list<int>|null apartment numbers with 2+ main permits, null while the house has not adopted rules
     */
    public function perApartment(): ?array
    {
        return Gate::allows('parking-analytics-apartments') ? app(ParkingAnalytics::class)->perApartment() : null;
    }
}
