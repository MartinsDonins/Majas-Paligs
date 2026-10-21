<?php

declare(strict_types=1);

namespace App\Filament\Manage\Pages;

use App\Enums\DocumentKind;
use App\Models\BuildingDocument;
use App\Services\Documents\DocumentRenderer;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Dokumenti" (ROUND2-SPEC §1.2): every version of the house documents — kind, version, status, dates — with PDF
 * downloads in LV and RU. building_admin sees all; a parking_officer reads the DPIA-lite and the parking decision
 * (BuildingDocumentPolicy). Editing happens in the opening wizard; this page only lists and downloads.
 */
final class DocumentsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?int $navigationSort = 71;

    protected static ?string $slug = 'documents';

    protected string $view = 'filament.manage.pages.documents';

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.governance');
    }

    public static function getNavigationLabel(): string
    {
        return __('opening.documents.title');
    }

    public function getTitle(): string
    {
        return __('opening.documents.title');
    }

    public static function canAccess(): bool
    {
        return CurrentBuilding::find() !== null && Gate::allows('viewAny', BuildingDocument::class);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    /**
     * @return Collection<int, BuildingDocument>
     */
    public function documents(): Collection
    {
        return BuildingDocument::query()->orderBy('kind')->orderByDesc('version')->get()
            ->filter(fn (BuildingDocument $d): bool => Gate::allows('view', $d))
            ->values();
    }

    public function wizardUrl(DocumentKind $kind): string
    {
        $step = match ($kind) {
            DocumentKind::Dpa, DocumentKind::DpaAnnex => 'dpa',
            DocumentKind::PrivacyNotice => 'notice',
            DocumentKind::DpiaLite => 'dpia',
            DocumentKind::ParkingDecision => 'mandate',
        };

        return HouseOpeningWizardPage::getUrl(tenant: CurrentBuilding::get()).'#step-'.$step;
    }

    public function download(string $id, string $locale): StreamedResponse
    {
        $document = BuildingDocument::query()->findOrFail($id);
        Gate::authorize('view', $document);
        $locale = $locale === 'ru' ? 'ru' : 'lv';
        $building = CurrentBuilding::get();

        return DocumentRenderer::download(
            app(DocumentRenderer::class)->pdfBytes($document, $building, $locale),
            DocumentRenderer::filename($building, $document->kind->value, $document->version, $locale),
        );
    }
}
