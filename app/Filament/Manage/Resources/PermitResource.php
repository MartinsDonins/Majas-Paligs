<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Auth\StepUpService;
use App\Enums\PermitSource;
use App\Enums\PermitStatus;
use App\Enums\PermitType;
use App\Enums\RevealReason;
use App\Filament\Manage\Resources\Concerns\ParkingStaff;
use App\Filament\Manage\Resources\PermitResource\Pages;
use App\Models\Apartment;
use App\Models\Permit;
use App\Models\Section;
use App\Models\Vehicle;
use App\Services\Parking\ParkingActionFailed;
use App\Services\Parking\PermitObservationService;
use App\Services\Parking\PermitRegisterQuery;
use App\Services\Parking\PermitService;
use App\Services\Parking\RevealService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Permit register (PARK-001 → PARK-011) — parking_officer / building_admin at gate L1: number, type, apartment,
 * stairwell, validity ("beidzas pēc 12 d."), status (icon + word), vehicle ALIAS for main permits (never a plate or a
 * plate fragment — ParkingStaff), observation count for guest permits, source and flags from PermitRegisterQuery (one
 * query per page, no decryption). Filters: type, status, stairwell, validity, source, "Dzīvokļi ar vairāk nekā 1
 * caurlaidi", "Viesu caurlaide vienmēr vienam auto", main without vehicle, vehicle pending, conflict.
 *
 * Actions: issue, replace on a plate change (history kept), return / lost / revoke, "Atzīmēt redzēto auto" on guest
 * permits (sealed observation, no lookup quota, audited), Reveal of a main permit's car (existing RevealService: reason,
 * 10/day, resident notice), bulk "Pagarināt līdz…" (mfa step-up). Global search stays off.
 */
final class PermitResource extends Resource
{
    use ParkingStaff;

    protected static ?string $model = Permit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 52;

    protected static bool $isGloballySearchable = false;

    /** @var array<int, array{flags: list<string>, main: int, total: int, observations: int}> per-request page cache */
    private static array $register = [];

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.parking');
    }

    public static function getModelLabel(): string
    {
        return __('admin.parking.permit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.parking.permit.plural');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', Permit::class);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('number')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['apartment:id,number,section_id', 'apartment.section:id,number', 'vehicle'])->withCount('observations'))
            ->columns([
                TextColumn::make('number')->label(__('admin.parking.permit.number'))->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('type')->label(__('admin.parking.permit.type'))->badge()
                    ->icon(fn (PermitType $state) => $state === PermitType::Main ? Heroicon::OutlinedHome : Heroicon::OutlinedUserGroup)
                    ->formatStateUsing(fn (PermitType $state) => $state->label()),
                TextColumn::make('apartment.number')->label(__('admin.apartment.number'))->sortable(),
                TextColumn::make('apartment.section.number')->label(__('admin.parking.register.stairwell'))
                    ->formatStateUsing(fn ($state) => $state !== null ? __('admin.parking.register.stairwell_n', ['n' => $state]) : '—'),
                TextColumn::make('plate')->label(__('admin.parking.register.vehicle'))->fontFamily('mono')
                    ->getStateUsing(fn (Permit $record): string => $record->type === PermitType::Main
                        ? self::vehicleAlias($record->vehicle)
                        : __('admin.parking.register.observations', ['n' => (int) ($record->observations_count ?? 0)])),
                TextColumn::make('valid_from')->label(__('admin.parking.permit.valid_from'))->date('d.m.Y')->sortable(),
                TextColumn::make('valid_to')->label(__('admin.parking.permit.valid_to'))->date('d.m.Y')->placeholder('—')->sortable()
                    ->description(fn (Permit $record): ?string => self::endsIn($record)),
                TextColumn::make('state')->label(__('admin.parking.permit.status'))->badge()
                    ->getStateUsing(fn (Permit $record): string => $record->displayState())
                    ->formatStateUsing(fn (string $state): string => __('parking.permit_state.'.$state))
                    ->icon(fn (string $state) => match ($state) {
                        'active' => Heroicon::OutlinedCheckCircle,
                        'expiring' => Heroicon::OutlinedClock,
                        'expired' => Heroicon::OutlinedExclamationCircle,
                        default => Heroicon::OutlinedMinusCircle,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'expiring', 'expired' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('source')->label(__('admin.parking.register.source'))
                    ->formatStateUsing(fn (PermitSource $state) => $state->label())->toggleable(),
                TextColumn::make('apartment_permits')->label(__('admin.parking.register.apartment_permits'))
                    ->getStateUsing(fn (Permit $record, HasTable $livewire): string => self::apartmentCounts($record, $livewire))
                    ->toggleable(),
                TextColumn::make('flags')->label(__('admin.parking.register.flags'))->badge()->color('warning')
                    ->icon(Heroicon::OutlinedFlag)
                    ->getStateUsing(fn (Permit $record, HasTable $livewire): array => array_map(
                        static fn (string $f): string => __('admin.parking.register.flag.'.$f),
                        self::registerRow($record, $livewire)['flags'],
                    ))
                    ->placeholder('—'),
                TextColumn::make('replaced_by_permit_id')->label(__('admin.parking.permit.replaced'))
                    ->formatStateUsing(fn ($state) => $state !== null ? '→ #'.$state : '')->placeholder('')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.parking.permit.status'))->options(PermitStatus::options())->default('active'),
                SelectFilter::make('type')->label(__('admin.parking.permit.type'))->options(PermitType::options()),
                SelectFilter::make('stairwell')->label(__('admin.parking.register.stairwell'))
                    ->options(fn (): array => Section::query()->orderBy('number')->pluck('number', 'id')
                        ->map(fn ($n): string => __('admin.parking.register.stairwell_n', ['n' => $n]))->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('apartment', fn (Builder $q) => $q->where('section_id', (int) $data['value']))
                        : $query),
                SelectFilter::make('validity')->label(__('admin.parking.register.validity'))
                    ->options([
                        'active' => __('admin.parking.register.validity_active'),
                        'expiring' => __('admin.parking.register.validity_expiring'),
                        'expired' => __('admin.parking.register.validity_expired'),
                    ])
                    ->query(fn (Builder $query, array $data) => self::validity($query, $data['value'] ?? null)),
                SelectFilter::make('source')->label(__('admin.parking.register.source'))->options(PermitSource::options()),
                Filter::make('multi')->label(__('admin.parking.register.filter.multi'))->query(fn (Builder $query) => PermitRegisterQuery::whereMultiPermit($query)),
                Filter::make('guest_same_car')->label(__('admin.parking.register.filter.guest_same_car'))->query(fn (Builder $query) => PermitRegisterQuery::whereGuestSameCar($query)),
                Filter::make('main_without_vehicle')->label(__('admin.parking.register.filter.main_without_vehicle'))->query(fn (Builder $query) => PermitRegisterQuery::whereMainWithoutVehicle($query)),
                Filter::make('vehicle_pending')->label(__('admin.parking.register.filter.vehicle_pending'))->query(fn (Builder $query) => PermitRegisterQuery::whereVehiclePending($query)),
                Filter::make('conflict')->label(__('admin.parking.register.filter.conflict'))->query(fn (Builder $query) => PermitRegisterQuery::whereConflict($query)),
            ])
            ->headerActions([
                Action::make('issue')
                    ->label(__('admin.parking.permit.issue'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => Gate::allows('create', Permit::class))
                    ->schema([
                        Select::make('apartment_id')->label(__('admin.apartment.label'))->options(fn (): array => self::apartmentOptions())->searchable()->required()->live(),
                        Select::make('type')->label(__('admin.parking.permit.type'))->options(PermitType::options())->default('main')->required()->live(),
                        TextInput::make('number')->label(__('admin.parking.permit.number'))->required()->maxLength(32),
                        Select::make('vehicle_id')->label(__('admin.parking.vehicle.label'))
                            ->options(fn (Get $get): array => self::vehicleOptions($get('apartment_id')))
                            ->visible(fn (Get $get): bool => $get('type') === 'main')
                            ->required(fn (Get $get): bool => $get('type') === 'main')
                            ->helperText(__('admin.parking.permit.vehicle_help')),
                        DatePicker::make('valid_from')->label(__('admin.parking.permit.valid_from'))->native(false)->displayFormat('d.m.Y')->default(now('Europe/Riga')->toDateString())->required(),
                        DatePicker::make('valid_to')->label(__('admin.parking.permit.valid_to'))->native(false)->displayFormat('d.m.Y'),
                        TextInput::make('issued_by')->label(__('admin.parking.permit.issued_by'))->maxLength(120),
                        Textarea::make('note_private')->label(__('admin.parking.permit.note'))->maxLength(500),
                    ])
                    ->action(function (array $data): void {
                        Gate::authorize('create', Permit::class);
                        $type = PermitType::from((string) $data['type']);
                        $vehicle = $type === PermitType::Main && filled($data['vehicle_id'] ?? null) ? Vehicle::query()->find((string) $data['vehicle_id']) : null;

                        try {
                            app(PermitService::class)->issue(
                                self::actor(),
                                Apartment::query()->findOrFail((int) $data['apartment_id']),
                                (string) $data['number'],
                                $type,
                                $vehicle,
                                CarbonImmutable::parse((string) $data['valid_from']),
                                filled($data['valid_to'] ?? null) ? CarbonImmutable::parse((string) $data['valid_to']) : null,
                                $data['issued_by'] ?? null,
                                $data['note_private'] ?? null,
                            );
                        } catch (ParkingActionFailed $e) {
                            Notification::make()->danger()->title($e->label())->send();

                            return;
                        }
                        Notification::make()->success()->title(__('admin.parking.saved'))->send();
                    }),
            ])
            ->recordActions([
                Action::make('replace')
                    ->label(__('admin.parking.permit.replace'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (Permit $record): bool => $record->status === PermitStatus::Active && $record->type === PermitType::Main && Gate::allows('update', $record))
                    ->schema([
                        Select::make('vehicle_id')->label(__('admin.parking.vehicle.label'))
                            ->options(fn (Permit $record): array => self::vehicleOptions($record->apartment_id, exclude: $record->vehicle_id))->required(),
                        TextInput::make('number')->label(__('admin.parking.permit.new_number'))->maxLength(32)->helperText(__('admin.parking.permit.new_number_help')),
                    ])
                    ->action(function (Permit $record, array $data): void {
                        Gate::authorize('update', $record);
                        try {
                            app(PermitService::class)->replace(self::actor(), $record, Vehicle::query()->findOrFail((string) $data['vehicle_id']), $data['number'] ?? null);
                        } catch (ParkingActionFailed $e) {
                            Notification::make()->danger()->title($e->label())->send();

                            return;
                        }
                        Notification::make()->success()->title(__('admin.parking.saved'))->send();
                    }),
                Action::make('observe')
                    ->label(__('admin.parking.register.observe'))
                    ->icon(Heroicon::OutlinedEye)
                    ->visible(fn (Permit $record): bool => $record->type === PermitType::Guest && $record->status === PermitStatus::Active && Gate::allows('update', $record))
                    ->modalDescription(__('admin.parking.register.observe_help'))
                    ->schema([
                        TextInput::make('plate')->label(__('admin.parking.vehicle.plate'))->required()->maxLength(16)
                            ->autocomplete('off')->extraInputAttributes(['spellcheck' => 'false', 'autocapitalize' => 'characters']),
                        DatePicker::make('observed_on')->label(__('admin.parking.register.observed_on'))->native(false)->displayFormat('d.m.Y')
                            ->default(now('Europe/Riga')->toDateString())->maxDate(now('Europe/Riga')->toDateString())->required(),
                    ])
                    ->action(function (Permit $record, array $data): void {
                        Gate::authorize('update', $record);
                        try {
                            app(PermitObservationService::class)->record(self::actor(), $record, (string) $data['plate'], CarbonImmutable::parse((string) $data['observed_on'], 'Europe/Riga'));
                        } catch (ParkingActionFailed $e) {
                            Notification::make()->danger()->title($e->label())->send();

                            return;
                        }
                        self::$register = [];
                        Notification::make()->success()->title(__('admin.parking.register.observed'))->send();
                    }),
                Action::make('reveal')
                    ->label(__('admin.parking.reveal.action'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('warning')
                    ->visible(fn (Permit $record): bool => $record->type === PermitType::Main && $record->vehicle !== null && Gate::allows('view', $record->vehicle))
                    ->modalDescription(__('admin.parking.reveal.logged'))
                    ->schema([
                        Select::make('reason')->label(__('admin.parking.reveal.reason'))->options(RevealReason::options())->required(),
                        Textarea::make('reason_text')->label(__('admin.parking.reveal.reason_text'))->maxLength(300)->helperText(__('admin.parking.reveal.logged')),
                    ])
                    ->action(function (Permit $record, array $data): void {
                        $vehicle = $record->vehicle;
                        if ($vehicle === null || ! Gate::allows('reveal', $vehicle)) {
                            Notification::make()->danger()->title(__('admin.parking.reveal.step_up'))->send();

                            return;
                        }
                        try {
                            $r = app(RevealService::class)->revealVehicle(self::actor(), $vehicle, RevealReason::from((string) $data['reason']), $data['reason_text'] ?? null);
                        } catch (ParkingActionFailed $e) {
                            Notification::make()->danger()->title($e->label())->send();

                            return;
                        }
                        Notification::make()->warning()->persistent()
                            ->title(__('admin.parking.reveal.result', ['plate' => $r['plate'], 'apartment' => $r['apartment']]))
                            ->send();
                    }),
                Action::make('status')
                    ->label(__('admin.parking.permit.change_status'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('gray')
                    ->visible(fn (Permit $record): bool => $record->status === PermitStatus::Active && Gate::allows('update', $record))
                    ->schema([
                        Select::make('status')->label(__('admin.parking.permit.status'))->required()->options([
                            'returned' => PermitStatus::Returned->label(),
                            'lost' => PermitStatus::Lost->label(),
                            'revoked' => PermitStatus::Revoked->label(),
                        ]),
                    ])
                    ->action(function (Permit $record, array $data): void {
                        Gate::authorize('update', $record);
                        app(PermitService::class)->setStatus(self::actor(), $record, PermitStatus::from((string) $data['status']));
                        Notification::make()->success()->title(__('admin.parking.saved'))->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('extend')
                    ->label(__('admin.parking.register.extend'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->visible(fn (): bool => Gate::allows('create', Permit::class))
                    ->schema([
                        DatePicker::make('valid_to')->label(__('admin.parking.register.extend_to'))->native(false)->displayFormat('d.m.Y')->required(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        Gate::authorize('create', Permit::class);
                        $actor = self::actor();
                        if (! app(StepUpService::class)->hasFreshMfa($actor)) {
                            Notification::make()->danger()->title(__('admin.parking.reveal.step_up'))->send();

                            return;
                        }

                        $to = CarbonImmutable::parse((string) $data['valid_to'], 'Europe/Riga');
                        $done = 0;
                        $failed = 0;
                        foreach ($records as $permit) {
                            if (! $permit instanceof Permit) {
                                continue;
                            }
                            try {
                                app(PermitService::class)->extend($actor, $permit, $to);
                                $done++;
                            } catch (ParkingActionFailed) {
                                $failed++;
                            }
                        }
                        Notification::make()->success()->title(__('admin.parking.register.extended', ['done' => $done, 'failed' => $failed]))->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /**
     * Flags + apartment counts of the record, loaded for the whole current page in one query.
     *
     * @return array{flags: list<string>, main: int, total: int, observations: int}
     */
    private static function registerRow(Permit $record, HasTable $livewire): array
    {
        if (! isset(self::$register[$record->id])) {
            $records = $livewire->getTableRecords();
            $items = $records instanceof \Illuminate\Support\Collection ? $records->all() : $records->items();
            $ids = collect($items)->pluck('id')->map(fn ($id): int => (int) $id)->push((int) $record->id)->unique()->values()->all();
            self::$register = app(PermitRegisterQuery::class)->flags($ids) + self::$register;
        }

        return self::$register[$record->id] ?? ['flags' => [], 'main' => 0, 'total' => 0, 'observations' => 0];
    }

    private static function apartmentCounts(Permit $record, HasTable $livewire): string
    {
        $row = self::registerRow($record, $livewire);

        return __('admin.parking.register.apartment_counts', ['main' => $row['main'], 'total' => $row['total']]);
    }

    /**
     * "beidzas pēc 12 d." under the end date of an active permit ending within 30 days.
     */
    private static function endsIn(Permit $record): ?string
    {
        if (! $record->expiresSoon() || $record->valid_to === null) {
            return null;
        }

        $days = (int) CarbonImmutable::now('Europe/Riga')->startOfDay()->diffInDays($record->valid_to);

        return __('admin.parking.register.ends_in', ['days' => $days]);
    }

    /**
     * @param  Builder<Permit>  $query
     * @return Builder<Permit>
     */
    private static function validity(Builder $query, ?string $value): Builder
    {
        $today = CarbonImmutable::now('Europe/Riga')->toDateString();
        $soon = CarbonImmutable::now('Europe/Riga')->addDays(30)->toDateString();

        return match ($value) {
            'active' => $query->where('status', 'active')->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today)),
            'expiring' => $query->where('status', 'active')->whereBetween('valid_to', [$today, $soon]),
            'expired' => $query->where(fn (Builder $q) => $q->where('status', 'expired')->orWhere(fn (Builder $q) => $q->where('status', 'active')->where('valid_to', '<', $today))),
            default => $query,
        };
    }

    /**
     * Active vehicles of an apartment (aliases only).
     *
     * @return array<string, string>
     */
    private static function vehicleOptions(mixed $apartmentId, ?string $exclude = null): array
    {
        if (! filled($apartmentId)) {
            return [];
        }

        return Vehicle::query()->active()->where('apartment_id', (int) $apartmentId)
            ->when($exclude !== null, fn (Builder $q) => $q->whereKeyNot($exclude))
            ->get()
            ->mapWithKeys(fn (Vehicle $v): array => [(string) $v->id => self::vehicleAlias($v).' · '.$v->created_at->format('d.m.Y').($v->is_company_car ? ' · '.__('parking.vehicle.company') : '')])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPermits::route('/'),
        ];
    }
}
