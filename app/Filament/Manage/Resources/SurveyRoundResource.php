<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Casts\PgArray;
use App\Enums\SurveyRoundStatus;
use App\Filament\Manage\Resources\SurveyRoundResource\Pages;
use App\Models\HeatingReport;
use App\Models\Section;
use App\Models\SurveyRound;
use App\Models\User;
use App\Services\Heating\SurveyRoundService;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Survey rounds (HEAT-003): building_admin (any scope, also the whole building) and section_elder (own stairwells
 * only — the list shows only rounds whose section_ids ⊆ their stairwells). Writes go through SurveyRoundService
 * (SectionScope + policy + DB trigger + audit).
 */
final class SurveyRoundResource extends Resource
{
    protected static ?string $model = SurveyRound::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 40;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.heating');
    }

    public static function getModelLabel(): string
    {
        return __('admin.round.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.round.plural');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', SurveyRound::class);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('admin.round.title')),
                TextColumn::make('scope')->label(__('admin.round.sections'))
                    ->getStateUsing(fn (SurveyRound $record): string => self::scopeLabel($record)),
                TextColumn::make('starts_at')->label(__('admin.round.starts'))->dateTime('d.m.Y H:i', 'Europe/Riga')->sortable(),
                TextColumn::make('ends_at')->label(__('admin.round.ends'))->dateTime('d.m.Y H:i', 'Europe/Riga'),
                TextColumn::make('status')->label(__('admin.round.status'))
                    ->formatStateUsing(fn (SurveyRoundStatus $state) => $state->label())
                    ->badge()
                    ->color(fn (SurveyRoundStatus $state) => match ($state) {
                        SurveyRoundStatus::Open => 'success',
                        SurveyRoundStatus::Draft => 'gray',
                        SurveyRoundStatus::Closed => 'warning',
                    }),
                TextColumn::make('apartments')->label(__('admin.round.apartments'))
                    ->getStateUsing(fn (SurveyRound $record): int => HeatingReport::query()->active()->where('survey_round_id', $record->id)->distinct()->count('apartment_id')),
            ])
            ->headerActions([
                Action::make('create')
                    ->label(__('admin.round.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => Gate::allows('create', SurveyRound::class))
                    ->schema(self::formSchema())
                    ->action(function (array $data): void {
                        app(SurveyRoundService::class)->save(self::actor(), self::payload($data));
                        Notification::make()->success()->title(__('admin.round.saved'))->send();
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.round.open'))
                    ->color('success')
                    ->visible(fn (SurveyRound $record): bool => $record->status !== SurveyRoundStatus::Open && Gate::allows('update', $record))
                    ->requiresConfirmation()
                    ->action(fn (SurveyRound $record) => app(SurveyRoundService::class)->transition(self::actor(), $record, SurveyRoundStatus::Open)),
                Action::make('close')
                    ->label(__('admin.round.close'))
                    ->color('warning')
                    ->visible(fn (SurveyRound $record): bool => $record->status === SurveyRoundStatus::Open && Gate::allows('update', $record))
                    ->requiresConfirmation()
                    ->action(fn (SurveyRound $record) => app(SurveyRoundService::class)->transition(self::actor(), $record, SurveyRoundStatus::Closed)),
                Action::make('edit')
                    ->label(__('admin.round.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (SurveyRound $record): bool => Gate::allows('update', $record))
                    ->fillForm(fn (SurveyRound $record): array => [
                        'title_lv' => $record->getTranslation('title', 'lv'),
                        'title_ru' => $record->getTranslation('title', 'ru'),
                        'whole_building' => $record->section_ids === null,
                        'section_ids' => $record->section_ids ?? [],
                        'starts_at' => $record->starts_at->setTimezone('Europe/Riga')->format('Y-m-d H:i:s'),
                        'ends_at' => $record->ends_at->setTimezone('Europe/Riga')->format('Y-m-d H:i:s'),
                        'status' => $record->status->value,
                    ])
                    ->schema(self::formSchema())
                    ->action(function (SurveyRound $record, array $data): void {
                        app(SurveyRoundService::class)->save(self::actor(), self::payload($data), $record);
                        Notification::make()->success()->title(__('admin.round.saved'))->send();
                    }),
            ]);
    }

    /**
     * @return list<Component|Field>
     */
    private static function formSchema(): array
    {
        $isAdmin = self::actor()->isBuildingAdmin(CurrentBuilding::get());

        return [
            TextInput::make('title_lv')->label(__('admin.round.title_lv'))->required()->maxLength(120),
            TextInput::make('title_ru')->label(__('admin.round.title_ru'))->required()->maxLength(120),
            Toggle::make('whole_building')->label(__('admin.round.whole_building'))->visible($isAdmin)->live(),
            CheckboxList::make('section_ids')->label(__('admin.round.sections'))
                ->options(fn (): array => self::allowedSections())
                ->columns(5)
                ->required(fn (Get $get): bool => ! $get('whole_building'))
                ->hidden(fn (Get $get): bool => (bool) $get('whole_building')),
            DateTimePicker::make('starts_at')->label(__('admin.round.starts'))->required()->seconds(false)->timezone('Europe/Riga'),
            DateTimePicker::make('ends_at')->label(__('admin.round.ends'))->required()->seconds(false)->timezone('Europe/Riga')->after('starts_at'),
            Select::make('status')->label(__('admin.round.status'))->options(SurveyRoundStatus::options())->default('draft')->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title_lv: string, title_ru: string, section_ids: array<int|string, mixed>|null, starts_at: string, ends_at: string, status: string}
     */
    private static function payload(array $data): array
    {
        return [
            'title_lv' => (string) $data['title_lv'],
            'title_ru' => (string) $data['title_ru'],
            'section_ids' => ($data['whole_building'] ?? false) ? null : array_values((array) ($data['section_ids'] ?? [])),
            'starts_at' => (string) $data['starts_at'],
            'ends_at' => (string) $data['ends_at'],
            'status' => (string) ($data['status'] ?? 'draft'),
        ];
    }

    /**
     * Sections the actor may include: building_admin all, elder own.
     *
     * @return array<int, string>
     */
    private static function allowedSections(): array
    {
        $user = self::actor();
        $building = CurrentBuilding::get();

        return Section::query()
            ->when(! $user->isBuildingAdmin($building), fn (Builder $q) => $q->whereIn('id', $user->elderSectionIds($building)))
            ->orderBy('number')
            ->pluck('number', 'id')
            ->map(fn ($n): string => __('admin.section.short', ['n' => $n]))
            ->all();
    }

    private static function scopeLabel(SurveyRound $round): string
    {
        if ($round->section_ids === null) {
            return __('admin.round.whole_building');
        }

        return Section::query()->whereIn('id', $round->section_ids)->orderBy('number')->pluck('number')->map(fn ($n) => $n.'.')->implode(', ');
    }

    private static function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * Elders see only rounds whose section_ids ⊆ their stairwells (never whole-building rounds).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = self::actor();
        $building = CurrentBuilding::get();

        if ($user->isBuildingAdmin($building)) {
            return $query;
        }

        return $query->whereNotNull('section_ids')
            ->whereRaw('section_ids <@ ?::bigint[]', [PgArray::literal($user->elderSectionIds($building), 'int')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSurveyRounds::route('/'),
        ];
    }
}
