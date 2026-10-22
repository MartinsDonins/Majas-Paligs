<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources\ResidentContactResource\Pages;

use App\Auth\StepUpService;
use App\Filament\Manage\Pages\HouseOpeningWizardPage;
use App\Filament\Manage\Pages\RosterImportPage;
use App\Filament\Manage\Resources\ResidentContactResource;
use App\Gdpr\RosterGate;
use App\Models\Apartment;
use App\Models\ResidentContact;
use App\Services\Roster\RosterExporter;
use App\Services\Roster\RosterViewer;
use App\Tenancy\CurrentBuilding;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * The roster list (one row per apartment, grouped by stairwell). Below gate L-R: the closed card with the missing
 * conditions and nothing else. Viewing the masked list is audited `roster.list` once per user per 10 minutes per
 * filter set (RosterViewer::logList).
 */
final class ListResidentContacts extends ListRecords
{
    protected static string $resource = ResidentContactResource::class;

    public function mount(): void
    {
        abort_unless(ResidentContactResource::canViewAny(), 403);

        parent::mount();
    }

    /** FIRSTRUN-001 (P1): the heading said "kontakti" (plural model label) while the menu says "Iedzīvotāju saraksts". */
    public function getTitle(): string
    {
        return __('roster.title');
    }

    public function getSubheading(): ?string
    {
        if (! RosterGate::isOpen()) {
            return null;
        }

        $apartments = ResidentContactResource::apartmentQuery();
        $total = (clone $apartments)->count();
        $withContact = (clone $apartments)->whereHas('residentContacts', fn (Builder $q) => $q->whereNull('moved_out_at'))->count();
        $contacts = ResidentContact::query()->whereNull('moved_out_at')->whereIn('apartment_id', (clone $apartments)->select('id'));

        return __('roster.list.stats', [
            'with' => $withContact,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($withContact * 100 / $total) : 0,
            'bis' => (clone $contacts)->where('bis_registered', true)->count(),
            'whatsapp' => (clone $contacts)->where('whatsapp_member', true)->count(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        $status = RosterGate::current();

        if ($status === null || ! $status->open) {
            return $schema->components([
                View::make('filament.manage.roster.closed')->viewData([
                    'missing' => $status?->missingLabels() ?? [],
                    'wizardUrl' => self::openingUrl(),
                ]),
            ]);
        }

        return parent::content($schema);
    }

    public function rendering(): void
    {
        if (! RosterGate::isOpen() || ! Gate::allows('viewAny', ResidentContact::class)) {
            return;
        }

        $sections = (array) ($this->tableFilters['section_id']['value'] ?? []);
        app(RosterViewer::class)->logList(
            ResidentContactResource::actor(),
            array_values(array_filter(array_map('intval', $sections))) ?: ResidentContactResource::visibleSectionIds(),
            $this->getFilteredTableQuery()?->count() ?? 0,
            (string) json_encode($this->tableFilters),
        );
    }

    /**
     * @return Builder<Apartment>
     */
    protected function getTableQuery(): Builder
    {
        return ResidentContactResource::apartmentQuery();
    }

    protected function getHeaderActions(): array
    {
        $open = RosterGate::isOpen();

        return [
            Action::make('create')
                ->label(__('roster.list.add'))
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => $open && ResidentContactResource::canCreate())
                ->url(fn (): string => ResidentContactResource::getUrl('create')),
            Action::make('import')
                ->label(__('roster.list.import'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->visible(fn (): bool => $open && Gate::allows('import', ResidentContact::class))
                ->url(fn (): string => RosterImportPage::getUrl()),
            Action::make('template')
                ->label(__('import.template.download'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->url(fn (): string => route('admin.import-template', ['building' => CurrentBuilding::get()->slug, 'kind' => 'roster'])),
            Action::make('export')
                ->label(__('roster.export.action'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => $open && ResidentContactResource::actor()->isBuildingAdmin(CurrentBuilding::get()))
                ->requiresConfirmation()
                ->modalDescription(__('roster.export.help'))
                ->action(function (): void {
                    $user = ResidentContactResource::actor();
                    if (! app(StepUpService::class)->demandStrong($user, $this)) {
                        return;
                    }
                    if (app(RosterExporter::class)->exportsToday() >= (int) config('majas.roster.export_per_day')) {
                        Notification::make()->danger()->title(__('roster.error.export_cap'))->send();

                        return;
                    }

                    $this->redirect(URL::temporarySignedRoute('admin.roster.export', now()->addMinutes((int) config('majas.exports.signed_minutes', 10)), [
                        'building' => CurrentBuilding::get()->slug,
                    ]));
                }),
        ];
    }

    public static function openingUrl(): string
    {
        return HouseOpeningWizardPage::getUrl().'#step-roster_scope';
    }
}
