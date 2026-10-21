<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Enums\BuildingRoleType;
use App\Gdpr\ParkingGate;
use App\Gdpr\ParkingGateStatus;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * "Stāvvietas statuss" (ROUND2-SPEC §3.7, UI-011, R2): ALWAYS visible to building_admin and parking_officer, first in
 * the Stāvvieta group — below L1 the group shows only this page and the sign (never an empty or hidden group). Explains
 * L0 / L1 / L2, lists exactly what is missing with links to the opening wizard steps, and says what residents see.
 * No personal data on this page. Residents' parking stays hidden until L2 + module on.
 */
final class ParkingStatusPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?int $navigationSort = 47;

    protected static ?string $slug = 'parking-status';

    protected string $view = 'filament.manage.pages.parking-status';

    /** Wizard step for each parking gate condition. */
    public const STEP = [
        'core_gate' => 'controller',
        'dpa_scope_parking' => 'parking_scope',
        'dpia_completed_at' => 'dpia',
        'privacy_notice_parking' => 'parking_notice',
        'mandate_ref' => 'mandate',
    ];

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.parking');
    }

    public static function getNavigationLabel(): string
    {
        return __('opening.parking_status.title');
    }

    public function getTitle(): string
    {
        return __('opening.parking_status.title');
    }

    public static function canAccess(): bool
    {
        $building = CurrentBuilding::find();
        $user = auth()->user();

        return $building !== null && $user instanceof User && ! $user->isTombstone()
            && ($user->hasRoleIn($building, BuildingRoleType::BuildingAdmin) || $user->hasRoleIn($building, BuildingRoleType::ParkingOfficer));
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function gate(): ParkingGateStatus
    {
        return ParkingGate::status(CurrentBuilding::get());
    }

    public function stepUrl(string $condition): string
    {
        return HouseOpeningWizardPage::getUrl(tenant: CurrentBuilding::get()).'#step-'.(self::STEP[$condition] ?? 'parking_on');
    }

    public function wizardUrl(): string
    {
        return HouseOpeningWizardPage::getUrl(tenant: CurrentBuilding::get());
    }
}
