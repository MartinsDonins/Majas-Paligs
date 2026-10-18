<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Enums\PermitStatusReason;
use App\Enums\RevealReason;
use App\Enums\VehicleSource;
use App\Enums\VehicleStatus;
use App\Filament\Manage\Resources\Concerns\ParkingStaff;
use App\Filament\Manage\Resources\VehicleResource\Pages;
use App\Models\Apartment;
use App\Models\Vehicle;
use App\Services\Parking\ParkingActionFailed;
use App\Services\Parking\RevealService;
use App\Services\Parking\VehicleRegistry;
use App\Services\Parking\VehicleRemovalService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Vehicles (PARK-001 / PARK-002.1) — parking_officer / building_admin at gate L1. Plates are MASKED ("AB••34");
 * the full plate and "find the apartment of a plate" are Reveal actions: fresh step-up, reason (text for "other"),
 * 10 per day, audited with the apartment's members as data subjects (they see it in "Kas skatīja manus datus") and
 * notified by e-mail. Verify re-checks uniqueness; delete goes through VehicleRemovalService. Global search is off.
 */
final class VehicleResource extends Resource
{
    use ParkingStaff;

    protected static ?string $model = Vehicle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 51;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.parking');
    }

    public static function getModelLabel(): string
    {
        return __('admin.parking.vehicle.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.parking.vehicle.plural');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', Vehicle::class);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Vehicle::query()->where('status', VehicleStatus::Pending->value)->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * @return list<Field>
     */
    private static function reasonFields(): array
    {
        return [
            Select::make('reason')->label(__('admin.parking.reveal.reason'))->options(RevealReason::options())->required(),
            Textarea::make('reason_text')->label(__('admin.parking.reveal.reason_text'))->maxLength(300)
                ->helperText(__('admin.parking.reveal.logged')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('apartment:id,number'))
            ->columns([
                TextColumn::make('plate')->label(__('admin.parking.vehicle.plate'))->fontFamily('mono')
                    ->getStateUsing(fn (Vehicle $record): string => self::maskedPlate($record)),
                TextColumn::make('country')->label(__('admin.parking.vehicle.country')),
                TextColumn::make('apartment.number')->label(__('admin.apartment.number'))->sortable(),
                TextColumn::make('status')->label(__('admin.parking.vehicle.status'))->badge()
                    ->formatStateUsing(fn (VehicleStatus $state) => $state->label())
                    ->color(fn (VehicleStatus $state) => match ($state) {
                        VehicleStatus::Active => 'success',
                        VehicleStatus::Pending => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('conflict')->label(__('admin.parking.vehicle.conflict'))->boolean()
                    ->trueIcon(Heroicon::OutlinedExclamationTriangle)->trueColor('warning')->falseIcon(null),
                IconColumn::make('is_company_car')->label(__('admin.parking.vehicle.company'))->boolean()->falseIcon(null),
                TextColumn::make('source')->label(__('admin.parking.vehicle.source'))->formatStateUsing(fn (VehicleSource $state) => $state->label()),
                TextColumn::make('created_at')->label(__('admin.parking.vehicle.declared'))->dateTime('d.m.Y', 'Europe/Riga')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.parking.vehicle.status'))->options(VehicleStatus::options()),
                TernaryFilter::make('conflict')->label(__('admin.parking.vehicle.conflict')),
            ])
            ->headerActions([
                Action::make('find')
                    ->label(__('admin.parking.reveal.find'))
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->visible(fn (): bool => Gate::allows('viewAny', Vehicle::class))
                    ->schema([
                        TextInput::make('plate')->label(__('admin.parking.vehicle.plate'))->required()->maxLength(16)->autocomplete(false),
                        ...self::reasonFields(),
                    ])
                    ->action(function (array $data): void {
                        if (! Gate::allows('reveal', Vehicle::class)) {
                            Notification::make()->danger()->title(__('admin.parking.reveal.step_up'))->send();

                            return;
                        }
                        try {
                            $found = app(RevealService::class)->findByPlate(self::actor(), (string) $data['plate'], RevealReason::from((string) $data['reason']), $data['reason_text'] ?? null);
                        } catch (ParkingActionFailed $e) {
                            Notification::make()->danger()->title($e->label())->send();

                            return;
                        }
                        $note = Notification::make()->persistent()
                            ->title($found !== null ? __('admin.parking.reveal.found', ['apartment' => $found['apartment']]) : __('admin.parking.reveal.not_found'));
                        ($found !== null ? $note->success() : $note->warning())->send();
                    }),
                Action::make('add')
                    ->label(__('admin.parking.vehicle.add'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => Gate::allows('create', Vehicle::class))
                    ->schema([
                        Select::make('apartment_id')->label(__('admin.apartment.label'))->options(fn (): array => self::apartmentOptions())->searchable()->required(),
                        TextInput::make('plate')->label(__('admin.parking.vehicle.plate'))->required()->maxLength(16)->autocomplete(false),
                        TextInput::make('country')->label(__('admin.parking.vehicle.country'))->default('LV')->maxLength(2),
                        Toggle::make('is_company_car')->label(__('admin.parking.vehicle.company')),
                    ])
                    ->action(function (array $data): void {
                        Gate::authorize('create', Vehicle::class);
                        try {
                            app(VehicleRegistry::class)->officerCreate(self::actor(), Apartment::query()->findOrFail((int) $data['apartment_id']),
                                (string) $data['plate'], $data['country'] ?? 'LV', (bool) ($data['is_company_car'] ?? false));
                        } catch (ParkingActionFailed $e) {
                            Notification::make()->danger()->title($e->label())->send();

                            return;
                        }
                        Notification::make()->success()->title(__('admin.parking.saved'))->send();
                    }),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label(__('admin.parking.vehicle.verify'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->visible(fn (Vehicle $record): bool => $record->status === VehicleStatus::Pending && Gate::allows('verify', $record))
                    ->schema([
                        TextInput::make('permit_number')->label(__('admin.parking.permit.new_number'))->maxLength(32)
                            ->helperText(__('admin.parking.vehicle.verify_help')),
                    ])
                    ->action(function (Vehicle $record, array $data): void {
                        Gate::authorize('verify', $record);
                        try {
                            app(VehicleRegistry::class)->activate(self::actor(), $record, $data['permit_number'] ?? null);
                        } catch (ParkingActionFailed $e) {
                            Notification::make()->danger()->title($e->label())->send();

                            return;
                        }
                        Notification::make()->success()->title(__('admin.parking.vehicle.verified'))->send();
                    }),
                Action::make('reject')
                    ->label(__('admin.parking.vehicle.reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Vehicle $record): bool => $record->status === VehicleStatus::Pending && Gate::allows('verify', $record))
                    ->action(function (Vehicle $record): void {
                        Gate::authorize('verify', $record);
                        app(VehicleRegistry::class)->reject(self::actor(), $record);
                    }),
                Action::make('reveal')
                    ->label(__('admin.parking.reveal.action'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('warning')
                    ->visible(fn (Vehicle $record): bool => Gate::allows('view', $record))
                    ->modalDescription(__('admin.parking.reveal.logged'))
                    ->schema(self::reasonFields())
                    ->action(function (Vehicle $record, array $data): void {
                        if (! Gate::allows('reveal', $record)) {
                            Notification::make()->danger()->title(__('admin.parking.reveal.step_up'))->send();

                            return;
                        }
                        try {
                            $r = app(RevealService::class)->revealVehicle(self::actor(), $record, RevealReason::from((string) $data['reason']), $data['reason_text'] ?? null);
                        } catch (ParkingActionFailed $e) {
                            Notification::make()->danger()->title($e->label())->send();

                            return;
                        }
                        Notification::make()->warning()->persistent()
                            ->title(__('admin.parking.reveal.result', ['plate' => $r['plate'], 'apartment' => $r['apartment']]))
                            ->send();
                    }),
                Action::make('remove')
                    ->label(__('admin.parking.delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.parking.vehicle.remove_help'))
                    ->visible(fn (Vehicle $record): bool => Gate::allows('delete', $record))
                    ->action(function (Vehicle $record): void {
                        Gate::authorize('delete', $record);
                        $revoked = app(VehicleRemovalService::class)->remove((string) $record->id, PermitStatusReason::Officer, actorUserId: (int) self::actor()->id);
                        Notification::make()->success()->title(__('admin.parking.vehicle.removed', ['count' => $revoked]))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
        ];
    }
}
