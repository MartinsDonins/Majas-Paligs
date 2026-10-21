<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Models\BuildingPrivacyNotice;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Building privacy notice — read-only history (ROUND2-SPEC §3.2 `notice`): the current notice (date, covered
 * modules, public link) and every earlier version with its withdrawal date. Preparing, editing and publishing a notice
 * moved to the opening wizard (one writer per gate input; lint + LV/RU editor there). building_admin only.
 */
final class BuildingPrivacyNoticePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 81;

    protected static ?string $slug = 'privacy-notice';

    protected string $view = 'filament.manage.pages.building-privacy-notice';

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.governance');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.notice.title');
    }

    public function getTitle(): string
    {
        return __('admin.notice.title');
    }

    public static function canAccess(): bool
    {
        $b = CurrentBuilding::find();

        return $b !== null && Gate::allows('manageNotices', $b);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function current(): ?BuildingPrivacyNotice
    {
        return BuildingPrivacyNotice::current();
    }

    /**
     * @return Collection<int, BuildingPrivacyNotice>
     */
    public function history(): Collection
    {
        return BuildingPrivacyNotice::query()->whereNotNull('published_at')->orderByDesc('published_at')->orderByDesc('id')->limit(50)->get();
    }

    public function wizardUrl(): string
    {
        return HouseOpeningWizardPage::getUrl(tenant: CurrentBuilding::get()).'#step-notice';
    }
}
