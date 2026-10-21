<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Building;
use App\Models\BuildingDocument;
use App\Models\User;
use App\Platform\PlatformBuildingContext;
use App\Platform\PlatformPurpose;
use App\Services\Documents\DocumentRefused;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\DocumentService;
use App\Support\BuildingSettings;
use App\Tenancy\BuildingIterator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Atvēršanas pieprasījumi" (ROUND2-SPEC §3.6): the queue of DPAs / annexes houses sent to CoreDigify. The list comes
 * from BuildingIterator (sanctioned: it sets the context per building and runs a scoped query for `sent` documents);
 * the PDF preview and "Ierakstīt parakstīto DPA" run inside PlatformBuildingContext with PlatformPurpose::OpeningReview
 * (platform_admin + fresh MFA, platform-chain audit). Recording pre-fills date / ref / scope from the request, writes
 * the DPA fields (gates re-evaluated) and notifies the building's admins. Not BreakGlass: no resident data is shown.
 */
final class OpeningRequestsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'opening-requests';

    protected string $view = 'filament.platform.pages.opening-requests';

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('opening.requests.title');
    }

    public function getTitle(): string
    {
        return __('opening.requests.title');
    }

    public function getSubheading(): string
    {
        return __('opening.requests.lead');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ! $user->isTombstone() && $user->isPlatformAdmin();
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    /**
     * @return list<array{building_id: int, building: string, slug: string, id: string, kind: DocumentKind, version: int, scope: list<string>, sent: string}>
     */
    public function rows(): array
    {
        $rows = [];

        BuildingIterator::each(static function (Building $building) use (&$rows): void {
            $sent = BuildingDocument::query()
                ->whereIn('kind', [DocumentKind::Dpa->value, DocumentKind::DpaAnnex->value])
                ->where('status', DocumentStatus::Sent->value)
                ->orderBy('sent_at')
                ->get();

            foreach ($sent as $document) {
                $rows[] = [
                    'building_id' => (int) $building->id,
                    'building' => (string) $building->name,
                    'slug' => (string) $building->slug,
                    'id' => (string) $document->id,
                    'kind' => $document->kind,
                    'version' => $document->version,
                    'scope' => $document->requestedScope(),
                    'sent' => $document->sent_at?->setTimezone('Europe/Riga')->format('d.m.Y H:i') ?? '',
                ];
            }
        });

        return $rows;
    }

    public function recordAction(): Action
    {
        return Action::make('record')
            ->label(__('opening.requests.record'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->size('sm')
            ->modalHeading(__('opening.requests.record_heading'))
            ->modalDescription(__('opening.requests.record_help'))
            ->fillForm(fn (array $arguments): array => [
                'dpa_signed_at' => now('Europe/Riga')->toDateString(),
                'dpa_ref' => null,
                'dpa_scope' => (array) ($arguments['scope'] ?? []),
            ])
            ->schema([
                DatePicker::make('dpa_signed_at')->label(__('admin.settings.dpa_signed_at'))->required()->native(false)->displayFormat('d.m.Y'),
                TextInput::make('dpa_ref')->label(__('admin.settings.dpa_ref'))->required()->maxLength(120),
                CheckboxList::make('dpa_scope')->label(__('admin.settings.dpa_scope'))->required()
                    ->options(collect(BuildingSettings::DPA_SCOPES)->mapWithKeys(fn (string $m): array => [$m => __('enum.module.'.$m)])->all())
                    ->columns(3),
            ])
            ->action(function (array $data, array $arguments): void {
                abort_unless(self::canAccess(), 403);
                /** @var User $admin */
                $admin = auth()->user();
                $building = Building::query()->findOrFail((int) ($arguments['building'] ?? 0));

                try {
                    $refused = PlatformBuildingContext::run($building, PlatformPurpose::OpeningReview, function () use ($admin, $data, $arguments): ?string {
                        $document = BuildingDocument::query()->findOrFail((string) ($arguments['document'] ?? ''));
                        try {
                            app(DocumentService::class)->record(
                                $admin,
                                $document,
                                (string) $data['dpa_signed_at'],
                                (string) $data['dpa_ref'],
                                array_values(array_map('strval', (array) $data['dpa_scope'])),
                            );
                        } catch (DocumentRefused $e) {
                            return $e->getMessage();
                        }

                        return null;
                    });
                } catch (AuthorizationException) {
                    Notification::make()->danger()->title(__('opening.error.step_up'))->send();

                    return;
                }

                $refused === null
                    ? Notification::make()->success()->title(__('opening.requests.recorded'))->send()
                    : Notification::make()->warning()->title($refused)->send();
            });
    }

    /**
     * PDF preview of a sent document (inside the named platform context).
     */
    public function pdf(int $buildingId, string $documentId, string $locale): StreamedResponse
    {
        abort_unless(self::canAccess(), 403);
        $building = Building::query()->findOrFail($buildingId);
        $locale = $locale === 'ru' ? 'ru' : 'lv';

        return PlatformBuildingContext::run($building, PlatformPurpose::OpeningReview, function (Building $b) use ($documentId, $locale): StreamedResponse {
            $document = BuildingDocument::query()->findOrFail($documentId);

            return DocumentRenderer::download(
                app(DocumentRenderer::class)->pdfBytes($document, $b, $locale),
                DocumentRenderer::filename($b, $document->kind->value, $document->version, $locale),
            );
        });
    }
}
