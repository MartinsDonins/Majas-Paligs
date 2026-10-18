<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Enums\IncidentKind;
use App\Enums\IncidentOrigin;
use App\Enums\IncidentStatus;
use App\Filament\Manage\Resources\IncidentResource\Pages;
use App\Models\Incident;
use App\Models\Riser;
use App\Models\Section;
use App\Models\User;
use App\Services\Heating\RiserLabel;
use App\Services\Outages\IncidentPresenter;
use App\Services\Outages\IncidentService;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Outages (HEAT-004): notices (elder: own stairwell; admin: any scope incl. the whole building), status, manager
 * reference and the manager reply (LV/RU, ≤160 chars) — building_admin. Confirmations are shown as a COUNT only.
 */
final class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 41;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.heating');
    }

    public static function getModelLabel(): string
    {
        return __('admin.incident.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.incident.plural');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('create', Incident::class);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Incident::query()->open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('confirmations')->with(['riser:id,code', 'section:id,number']))
            ->columns([
                TextColumn::make('kind')->label(__('admin.incident.kind'))->formatStateUsing(fn (IncidentKind $state) => $state->label())->badge(),
                TextColumn::make('origin')->label(__('admin.incident.origin'))->formatStateUsing(fn (IncidentOrigin $state) => $state->label()),
                TextColumn::make('scope')->label(__('admin.incident.scope'))
                    ->getStateUsing(fn (Incident $record): string => app(IncidentPresenter::class)->scope($record)),
                TextColumn::make('status')->label(__('admin.incident.status'))
                    ->formatStateUsing(fn (IncidentStatus $state) => $state->label())
                    ->badge()
                    ->color(fn (IncidentStatus $state) => $state->isOpen() ? 'warning' : 'success'),
                TextColumn::make('confirmations_count')->label(__('admin.incident.confirmations')),
                TextColumn::make('started_at')->label(__('admin.incident.started'))->dateTime('d.m.Y H:i', 'Europe/Riga')->sortable(),
                TextColumn::make('expected_end_at')->label(__('admin.incident.expected'))->dateTime('d.m.Y H:i', 'Europe/Riga'),
                TextColumn::make('manager_ref')->label(__('admin.incident.manager_ref')),
            ])
            ->filters([
                TernaryFilter::make('is_open')->label(__('admin.incident.open'))->default(true),
                SelectFilter::make('kind')->label(__('admin.incident.kind'))->options(IncidentKind::options()),
            ])
            ->headerActions([
                Action::make('notice')
                    ->label(__('admin.incident.notice'))
                    ->icon(Heroicon::OutlinedMegaphone)
                    ->visible(fn (): bool => Gate::allows('create', Incident::class))
                    ->schema([
                        Select::make('kind')->label(__('admin.incident.kind'))->options(IncidentKind::options())->required()->live(),
                        Select::make('target')->label(__('elder.notice.target'))->required()->live()
                            ->options(fn (): array => array_filter([
                                'building' => self::actor()->isBuildingAdmin(CurrentBuilding::get()) ? __('elder.notice.target_building') : null,
                                'section' => __('elder.notice.target_section'),
                                'column' => __('elder.notice.target_column'),
                                'riser' => __('elder.notice.target_riser'),
                            ])),
                        Select::make('section_id')->label(__('admin.section.label'))
                            ->options(fn (): array => self::allowedSections())
                            ->visible(fn (Get $get): bool => in_array($get('target'), ['section', 'column', 'riser'], true))
                            ->required(fn (Get $get): bool => in_array($get('target'), ['section', 'column', 'riser'], true))
                            ->live(),
                        TextInput::make('position')->label(__('elder.notice.position'))->numeric()->minValue(1)->maxValue(12)
                            ->visible(fn (Get $get): bool => $get('target') === 'column')
                            ->required(fn (Get $get): bool => $get('target') === 'column'),
                        Select::make('riser_id')->label(__('elder.notice.riser'))
                            ->options(fn (Get $get): array => self::risers($get('section_id'), $get('kind')))
                            ->visible(fn (Get $get): bool => $get('target') === 'riser')
                            ->required(fn (Get $get): bool => $get('target') === 'riser'),
                        TextInput::make('floors_from')->label(__('elder.notice.floors_from'))->numeric()->minValue(1)->maxValue(40),
                        TextInput::make('floors_to')->label(__('elder.notice.floors_to'))->numeric()->minValue(1)->maxValue(40),
                        Textarea::make('reason_lv')->label(__('elder.notice.reason_lv'))->required()->maxLength(300),
                        Textarea::make('reason_ru')->label(__('elder.notice.reason_ru'))->required()->maxLength(300),
                        DateTimePicker::make('expected_end_at')->label(__('elder.notice.expected_end'))->seconds(false)->timezone('Europe/Riga'),
                    ])
                    ->action(function (array $data): void {
                        app(IncidentService::class)->postNotice(self::actor(), IncidentKind::from((string) $data['kind']), [
                            'target' => (string) $data['target'],
                            'section_id' => isset($data['section_id']) ? (int) $data['section_id'] : null,
                            'position' => isset($data['position']) ? (int) $data['position'] : null,
                            'riser_id' => isset($data['riser_id']) ? (int) $data['riser_id'] : null,
                            'floors_from' => filled($data['floors_from'] ?? null) ? (int) $data['floors_from'] : null,
                            'floors_to' => filled($data['floors_to'] ?? null) ? (int) $data['floors_to'] : null,
                            'reason_lv' => (string) $data['reason_lv'],
                            'reason_ru' => (string) $data['reason_ru'],
                            'expected_end_at' => $data['expected_end_at'] ?? null,
                        ]);
                        Notification::make()->success()->title(__('elder.notice.posted'))->send();
                    }),
            ])
            ->recordActions([
                Action::make('update')
                    ->label(__('admin.incident.update'))
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->visible(fn (Incident $record): bool => Gate::allows('update', $record))
                    ->fillForm(fn (Incident $record): array => [
                        'status' => $record->status->value,
                        'manager_ref' => $record->manager_ref,
                        'reply_lv' => $record->getTranslation('manager_reply', 'lv', false),
                        'reply_ru' => $record->getTranslation('manager_reply', 'ru', false),
                    ])
                    ->schema([
                        Select::make('status')->label(__('admin.incident.status'))->options(IncidentStatus::options())->required(),
                        TextInput::make('manager_ref')->label(__('admin.incident.manager_ref'))->maxLength(60),
                        TextInput::make('reply_lv')->label(__('admin.incident.reply_lv'))->maxLength(160),
                        TextInput::make('reply_ru')->label(__('admin.incident.reply_ru'))->maxLength(160),
                    ])
                    ->action(function (Incident $record, array $data): void {
                        app(IncidentService::class)->update(
                            self::actor(),
                            $record,
                            IncidentStatus::from((string) $data['status']),
                            $data['manager_ref'] ?? null,
                            ['lv' => $data['reply_lv'] ?? null, 'ru' => $data['reply_ru'] ?? null],
                        );
                        Notification::make()->success()->title(__('admin.incident.saved'))->send();
                    }),
                Action::make('resolve')
                    ->label(__('elder.notice.resolve'))
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (Incident $record): bool => $record->is_open && Gate::allows('resolve', $record))
                    ->requiresConfirmation()
                    ->action(fn (Incident $record) => app(IncidentService::class)->resolve(self::actor(), $record)),
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

    /**
     * @return array<int, string>
     */
    private static function risers(mixed $sectionId, mixed $kind): array
    {
        $kind = IncidentKind::tryFrom((string) $kind);
        if ($sectionId === null || $kind === null || ! array_key_exists((int) $sectionId, self::allowedSections())) {
            return [];
        }

        return Riser::query()->where('section_id', (int) $sectionId)->where('kind', $kind->riserKind()->value)->orderBy('code')->get(['id', 'code'])
            ->mapWithKeys(fn (Riser $r): array => [$r->id => RiserLabel::for($r->code).' ('.$r->code.')'])->all();
    }

    private static function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIncidents::route('/'),
        ];
    }
}
