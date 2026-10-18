<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Audit\AuditLogger;
use App\Casts\PgArray;
use App\Enums\ManagerReportKind;
use App\Filament\Manage\Resources\ManagerReportResource\Pages;
use App\Http\Controllers\ManagerReportDownloadController;
use App\Models\ManagerReport;
use App\Models\Section;
use App\Models\SurveyRound;
use App\Models\User;
use App\Services\Heating\ManagerReportGenerator;
use App\Services\Heating\SectionScope;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Manager reports (HEAT-005): generate (building_admin any stairwells; elder own only), signed 10-min PDF/CSV
 * downloads, sent / response log. The snapshot is frozen at generation (DB trigger) — only sent_at, manager_ref and
 * the response change.
 */
final class ManagerReportResource extends Resource
{
    protected static ?string $model = ManagerReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 42;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.heating');
    }

    public static function getModelLabel(): string
    {
        return __('admin.report.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.report.plural');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', ManagerReport::class);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('generated_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('admin.report.number'))->sortable()->fontFamily('mono'),
                TextColumn::make('kind')->label(__('admin.report.kind'))->formatStateUsing(fn (ManagerReportKind $state) => $state->label())->badge(),
                TextColumn::make('sections')->label(__('admin.report.sections'))
                    ->getStateUsing(fn (ManagerReport $record): string => implode(', ', array_map(fn ($n) => $n.'.', $record->snapshot['sections'] ?? []))),
                TextColumn::make('sharing')->label(__('admin.report.sharing'))
                    ->getStateUsing(fn (ManagerReport $record): string => ($record->sharing['heating_floors'] ?? false)
                        ? (($record->sharing['apartment_numbers'] ?? false) ? __('admin.report.sharing_numbers') : __('admin.report.sharing_floors'))
                        : __('admin.report.sharing_none')),
                TextColumn::make('generated_at')->label(__('admin.report.generated'))->dateTime('d.m.Y H:i', 'Europe/Riga')->sortable(),
                TextColumn::make('sent_at')->label(__('admin.report.sent'))->dateTime('d.m.Y', 'Europe/Riga'),
                TextColumn::make('manager_ref')->label(__('admin.report.manager_ref')),
            ])
            ->headerActions([
                Action::make('generate')
                    ->label(__('admin.report.generate'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->visible(fn (): bool => Gate::allows('create', ManagerReport::class))
                    ->schema([
                        Select::make('kind')->label(__('admin.report.kind'))->options(ManagerReportKind::options())->default('heating')->required(),
                        CheckboxList::make('section_ids')->label(__('admin.report.sections'))->options(fn (): array => self::allowedSections())->columns(5)->required(),
                        Select::make('round_id')->label(__('admin.report.round'))
                            ->options(fn (): array => SurveyRound::query()->orderByDesc('starts_at')->limit(10)->get()->mapWithKeys(fn (SurveyRound $r): array => [$r->id => $r->title])->all())
                            ->placeholder(__('admin.report.no_round')),
                        DatePicker::make('from')->label(__('admin.report.from'))->native(false)->displayFormat('d.m.Y'),
                        DatePicker::make('to')->label(__('admin.report.to'))->native(false)->displayFormat('d.m.Y'),
                    ])
                    ->action(function (array $data): void {
                        $user = self::actor();
                        if (RateLimiter::tooManyAttempts('manager-report:'.$user->id, 10)) {
                            Notification::make()->warning()->title(__('heating.flash.report_limit'))->send();

                            return;
                        }
                        RateLimiter::hit('manager-report:'.$user->id, 3600);

                        try {
                            $scope = SectionScope::fromIds(array_values((array) $data['section_ids']));
                        } catch (InvalidArgumentException) {
                            Notification::make()->danger()->title(__('heating.round.invalid_sections'))->send();

                            return;
                        }

                        $report = app(ManagerReportGenerator::class)->generate(
                            $user,
                            ManagerReportKind::from((string) $data['kind']),
                            $scope,
                            filled($data['from'] ?? null) ? CarbonImmutable::parse((string) $data['from'], 'Europe/Riga')->startOfDay()->utc() : null,
                            filled($data['to'] ?? null) ? CarbonImmutable::parse((string) $data['to'], 'Europe/Riga')->endOfDay()->utc() : null,
                            filled($data['round_id'] ?? null) ? (int) $data['round_id'] : null,
                        );

                        Notification::make()->success()->title(__('admin.report.generated_title', ['number' => $report->number]))->send();
                    }),
            ])
            ->recordActions([
                Action::make('pdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->visible(fn (ManagerReport $record): bool => Gate::allows('download', $record))
                    ->url(fn (ManagerReport $record): string => ManagerReportDownloadController::url(CurrentBuilding::get(), $record, 'pdf'))
                    ->openUrlInNewTab(),
                Action::make('csv')
                    ->label('CSV')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->visible(fn (ManagerReport $record): bool => Gate::allows('download', $record))
                    ->url(fn (ManagerReport $record): string => ManagerReportDownloadController::url(CurrentBuilding::get(), $record, 'csv'))
                    ->openUrlInNewTab(),
                Action::make('sent')
                    ->label(__('admin.report.mark_sent'))
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->visible(fn (ManagerReport $record): bool => Gate::allows('update', $record))
                    ->fillForm(fn (ManagerReport $record): array => [
                        'sent_at' => ($record->sent_at ?? now())->setTimezone('Europe/Riga')->format('Y-m-d H:i:s'),
                        'manager_ref' => $record->manager_ref,
                        'response' => $record->response['text'] ?? null,
                    ])
                    ->schema([
                        DateTimePicker::make('sent_at')->label(__('admin.report.sent'))->seconds(false)->timezone('Europe/Riga')->required(),
                        TextInput::make('manager_ref')->label(__('admin.report.manager_ref'))->maxLength(60),
                        Textarea::make('response')->label(__('admin.report.response'))->maxLength(500),
                    ])
                    ->action(function (ManagerReport $record, array $data): void {
                        Gate::authorize('update', $record);
                        $record->forceFill([
                            'sent_at' => CarbonImmutable::parse((string) $data['sent_at'], 'Europe/Riga')->utc(),
                            'manager_ref' => filled($data['manager_ref'] ?? null) ? mb_substr(trim((string) $data['manager_ref']), 0, 60) : null,
                            'response' => filled($data['response'] ?? null) ? ['text' => mb_substr(trim((string) $data['response']), 0, 500), 'recorded_at' => now()->toIso8601String()] : null,
                        ])->save();
                        AuditLogger::record('manager_report.sent', $record, data: ['report_number' => $record->number, 'has_response' => filled($data['response'] ?? null)]);
                        Notification::make()->success()->title(__('admin.report.saved'))->send();
                    }),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function allowedSections(): array
    {
        $user = self::actor();
        $building = CurrentBuilding::get();

        return Section::query()
            ->when(! $user->isBuildingAdmin($building), fn (Builder $q) => $q->whereIn('id', $user->elderSectionIds($building)))
            ->orderBy('number')->pluck('number', 'id')
            ->map(fn ($n): string => __('admin.section.short', ['n' => $n]))->all();
    }

    private static function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * Elders see only reports scoped to their own stairwells.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = self::actor();
        $building = CurrentBuilding::get();

        if ($user->isBuildingAdmin($building)) {
            return $query;
        }

        return $query->whereRaw('section_ids <@ ?::bigint[]', [PgArray::literal($user->elderSectionIds($building), 'int')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListManagerReports::route('/'),
        ];
    }
}
