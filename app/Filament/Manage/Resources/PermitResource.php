<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Enums\PermitStatus;
use App\Enums\PermitType;
use App\Filament\Manage\Resources\Concerns\ParkingStaff;
use App\Filament\Manage\Resources\PermitResource\Pages;
use App\Models\Apartment;
use App\Models\Permit;
use App\Models\Vehicle;
use App\Services\Parking\ParkingActionFailed;
use App\Services\Parking\PermitService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Permits (PARK-001) — parking_officer / building_admin at gate L1: issue (main permits need an ACTIVE vehicle of
 * the same apartment; guest permits are never plate-bound), replace on a plate change (history kept), return / lost
 * / revoke. Plates are masked; overlapping active permits with the same number and type are refused by the DB.
 */
final class PermitResource extends Resource
{
    use ParkingStaff;

    protected static ?string $model = Permit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 52;

    protected static bool $isGloballySearchable = false;

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['apartment:id,number', 'vehicle']))
            ->columns([
                TextColumn::make('number')->label(__('admin.parking.permit.number'))->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('type')->label(__('admin.parking.permit.type'))->badge()->formatStateUsing(fn (PermitType $state) => $state->label()),
                TextColumn::make('apartment.number')->label(__('admin.apartment.number'))->sortable(),
                TextColumn::make('plate')->label(__('admin.parking.vehicle.plate'))->fontFamily('mono')
                    ->getStateUsing(fn (Permit $record): string => $record->type === PermitType::Main ? self::maskedPlate($record->vehicle) : '—'),
                TextColumn::make('valid_from')->label(__('admin.parking.permit.valid_from'))->date('d.m.Y'),
                TextColumn::make('valid_to')->label(__('admin.parking.permit.valid_to'))->date('d.m.Y')->placeholder('—'),
                TextColumn::make('status')->label(__('admin.parking.permit.status'))->badge()
                    ->formatStateUsing(fn (PermitStatus $state) => $state->label())
                    ->color(fn (PermitStatus $state) => $state === PermitStatus::Active ? 'success' : 'gray'),
                TextColumn::make('replaced_by_permit_id')->label(__('admin.parking.permit.replaced'))
                    ->formatStateUsing(fn ($state) => $state !== null ? '→ #'.$state : '')->placeholder(''),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.parking.permit.status'))->options(PermitStatus::options())->default('active'),
                SelectFilter::make('type')->label(__('admin.parking.permit.type'))->options(PermitType::options()),
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
            ]);
    }

    /**
     * Active vehicles of an apartment (masked plates).
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
            ->mapWithKeys(fn (Vehicle $v): array => [(string) $v->id => self::maskedPlate($v).($v->is_company_car ? ' · '.__('parking.vehicle.company') : '')])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPermits::route('/'),
        ];
    }
}
