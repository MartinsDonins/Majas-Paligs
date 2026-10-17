<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Audit\AuditLogger;
use App\Enums\ExportStatus;
use App\Gdpr\BuildingExporter;
use App\Models\BuildingExport;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * House data export (ADMIN-004, MVP-SPEC A.8): structure + aggregates, no personal data, for a change of
 * manager or controller. building_admin + step-up; audited; 10-minute signed download.
 */
final class BuildingExportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 82;

    protected static ?string $slug = 'export';

    protected string $view = 'filament.manage.pages.building-export';

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.governance');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.export.title');
    }

    public function getTitle(): string
    {
        return __('admin.export.title');
    }

    public static function canAccess(): bool
    {
        $b = CurrentBuilding::find();

        return $b !== null && Gate::allows('export', $b);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function requestExport(): void
    {
        $building = CurrentBuilding::get();
        Gate::authorize('export', $building);

        $export = BuildingExport::query()->create(['requested_by' => auth()->id(), 'status' => ExportStatus::Queued]);
        AuditLogger::record('building.export_requested', $export);
        BuildingExporter::dispatch((int) $building->id, (string) $export->id);

        Notification::make()->success()->title(__('admin.export.queued'))->send();
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    public function exports(): Collection
    {
        $slug = CurrentBuilding::get()->slug;

        return BuildingExport::query() // @phpstan-ignore return.type
            ->latest('created_at')->limit(10)->get()->map(fn (BuildingExport $e): array => [
                'id' => (string) $e->id,
                'status' => (string) $e->status->value,
                'created' => $e->created_at?->timezone('Europe/Riga')->format('d.m.Y H:i') ?? '',
                'url' => $e->status === ExportStatus::Ready && $e->expires_at?->isFuture()
                    ? URL::temporarySignedRoute('building.exports.download', now()->addMinutes((int) config('majas.exports.signed_minutes', 10)), ['building' => $slug, 'export' => $e->id])
                    : null,
            ]);
    }
}
