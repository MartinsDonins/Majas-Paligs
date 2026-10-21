<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Gdpr\ParkingGate;
use App\Gdpr\ParkingGateStatus;
use App\Models\ParkingLot;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * "Stāvvieta — sagatavošana" (GOV-001 / PARK-007): the parking gate status with every missing condition (the
 * explanation panel shown while the L1 resources are hidden) and the Art. 14 parking-lot sign PDF (LV/RU, QR to the
 * building privacy notice). Available from gate L0 — no personal data — to parking_officer / building_admin.
 */
final class ParkingSignPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 49;

    protected static ?string $slug = 'parking';

    protected string $view = 'filament.manage.pages.parking-sign';

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.parking');
    }

    /** "Zīme" — after "Stāvvietas statuss" in the Stāvvieta group (ROUND2-SPEC §1.2). */
    public static function getNavigationLabel(): string
    {
        return __('admin.parking.sign.nav');
    }

    public function getTitle(): string
    {
        return __('admin.parking.prepare.title');
    }

    public static function canAccess(): bool
    {
        return CurrentBuilding::find() !== null && Gate::allows('create', ParkingLot::class);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function gate(): ParkingGateStatus
    {
        return ParkingGate::status(CurrentBuilding::get());
    }

    /**
     * @return array<int, string>
     */
    public function lots(): array
    {
        return ParkingLot::query()->orderBy('sort')->orderBy('name')->pluck('name', 'id')->all();
    }

    public function signUrl(?string $lot = null): string
    {
        return route('building.parking-sign', CurrentBuilding::get()).($lot !== null ? '?lot='.rawurlencode($lot) : '');
    }
}
