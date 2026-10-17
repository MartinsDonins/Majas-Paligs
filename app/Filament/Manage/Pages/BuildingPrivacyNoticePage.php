<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Audit\AuditLogger;
use App\Enums\PrivacyNoticeKind;
use App\Gdpr\CoreGate;
use App\Gdpr\CoreGateMonitor;
use App\Models\BuildingPrivacyNotice;
use App\Models\PrivacyNoticeVersion;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Building privacy notice (house = controller): adopt a published template, preview LV/RU with the controller
 * filled in, publish (the previous notice is withdrawn) or withdraw. building_admin + step-up; audited; gate
 * L-core re-evaluated after each change.
 */
final class BuildingPrivacyNoticePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 81;

    protected static ?string $slug = 'privacy-notice';

    protected string $view = 'filament.manage.pages.building-privacy-notice';

    public ?int $templateId = null;

    #[Locked]
    public string $previewLocale = 'lv';

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
        $this->templateId = $this->templates()->first()?->id;
    }

    public function setLocale(string $locale): void
    {
        $this->previewLocale = $locale === 'ru' ? 'ru' : 'lv';
    }

    public function publish(): void
    {
        $building = CurrentBuilding::get();
        Gate::authorize('manageNotices', $building);

        $template = $this->templates()->firstWhere('id', $this->templateId);
        if ($template === null) {
            Notification::make()->danger()->title(__('admin.notice.no_template'))->send();

            return;
        }

        $controller = $building->settings->controller;
        if ($controller['name'] === null || $controller['contact'] === null) {
            Notification::make()->warning()->title(__('admin.notice.controller_first'))->send();

            return;
        }

        $before = CoreGate::status($building);

        DB::transaction(function () use ($template, $controller): void {
            $current = BuildingPrivacyNotice::current();
            $current?->forceFill(['withdrawn_at' => now()])->save();

            $notice = BuildingPrivacyNotice::query()->create([
                'template_version_id' => $template->id,
                'controller_snapshot' => ['name' => $controller['name'], 'contact' => $controller['contact']],
                'covers_modules' => array_values(array_intersect($template->covers_modules, ['core', 'heating', 'outages', 'parking'])),
                'published_at' => now(),
                'published_by' => auth()->id(),
            ]);

            AuditLogger::record('privacy_notice.publish', $notice, data: ['template_version' => $template->version, 'covers' => $notice->covers_modules]);
        });

        CoreGateMonitor::afterChange($building, $before);
        Notification::make()->success()->title(__('admin.notice.published'))->send();
    }

    public function withdraw(): void
    {
        $building = CurrentBuilding::get();
        Gate::authorize('manageNotices', $building);

        $current = BuildingPrivacyNotice::current();
        if ($current === null) {
            return;
        }

        $before = CoreGate::status($building);
        $current->forceFill(['withdrawn_at' => now()])->save();
        AuditLogger::record('privacy_notice.withdraw', $current);
        CoreGateMonitor::afterChange($building, $before);

        Notification::make()->warning()->title(__('admin.notice.withdrawn'))->send();
    }

    /**
     * @return Collection<int, PrivacyNoticeVersion>
     */
    public function templates()
    {
        return PrivacyNoticeVersion::query()
            ->where('kind', PrivacyNoticeKind::BuildingTemplate->value)
            ->published()
            ->orderByDesc('published_at')
            ->get();
    }

    public function current(): ?BuildingPrivacyNotice
    {
        return BuildingPrivacyNotice::current();
    }

    public function preview(): ?string
    {
        $template = $this->templates()->firstWhere('id', $this->templateId);

        return $template?->render($this->previewLocale, CurrentBuilding::get()->settings->controller);
    }
}
