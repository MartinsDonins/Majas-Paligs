<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Audit\AuditLogger;
use App\Filament\Manage\Resources\ParkingLotResource\Pages;
use App\Models\ParkingLot;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Parking lots (PARK-001) — no personal data, available from gate L0 so the house can prepare lots and signs before
 * any plate is stored. parking_officer / building_admin.
 */
final class ParkingLotResource extends Resource
{
    protected static ?string $model = ParkingLot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 50;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.parking');
    }

    public static function getModelLabel(): string
    {
        return __('admin.parking.lot.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.parking.lot.plural');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', ParkingLot::class);
    }

    /**
     * @return list<Field>
     */
    private static function fields(): array
    {
        return [
            TextInput::make('name')->label(__('admin.parking.lot.name'))->required()->maxLength(80),
            TextInput::make('capacity_estimate')->label(__('admin.parking.lot.capacity'))->numeric()->minValue(0)->maxValue(10000),
            TextInput::make('sort')->label(__('admin.parking.lot.sort'))->numeric()->default(0),
            Textarea::make('signage')->label(__('admin.parking.lot.signage'))->maxLength(500),
            Textarea::make('notes')->label(__('admin.parking.lot.notes'))->maxLength(500),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label(__('admin.parking.lot.name'))->sortable(),
                TextColumn::make('capacity_estimate')->label(__('admin.parking.lot.capacity')),
                TextColumn::make('signage')->label(__('admin.parking.lot.signage'))->limit(40),
            ])
            ->headerActions([
                Action::make('create')
                    ->label(__('admin.parking.lot.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => Gate::allows('create', ParkingLot::class))
                    ->schema(self::fields())
                    ->action(function (array $data): void {
                        Gate::authorize('create', ParkingLot::class);
                        try {
                            $lot = DB::transaction(fn (): ParkingLot => ParkingLot::query()->create(self::clean($data)));
                        } catch (QueryException) {
                            Notification::make()->danger()->title(__('admin.parking.lot.duplicate'))->send();

                            return;
                        }
                        AuditLogger::record('parking_lot.create', $lot);
                        Notification::make()->success()->title(__('admin.parking.saved'))->send();
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('admin.parking.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (ParkingLot $record): bool => Gate::allows('update', $record))
                    ->fillForm(fn (ParkingLot $record): array => $record->only(['name', 'capacity_estimate', 'sort', 'signage', 'notes']))
                    ->schema(self::fields())
                    ->action(function (ParkingLot $record, array $data): void {
                        Gate::authorize('update', $record);
                        $record->forceFill(self::clean($data))->save();
                        AuditLogger::record('parking_lot.update', $record);
                        Notification::make()->success()->title(__('admin.parking.saved'))->send();
                    }),
                Action::make('delete')
                    ->label(__('admin.parking.delete'))
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash)
                    ->requiresConfirmation()
                    ->visible(fn (ParkingLot $record): bool => Gate::allows('delete', $record))
                    ->action(function (ParkingLot $record): void {
                        Gate::authorize('delete', $record);
                        try {
                            DB::transaction(fn () => $record->delete());
                        } catch (QueryException) {
                            Notification::make()->danger()->title(__('admin.parking.lot.in_use'))->send();

                            return;
                        }
                        AuditLogger::record('parking_lot.delete', ['parking_lot', $record->id]);
                    }),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function clean(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'capacity_estimate' => filled($data['capacity_estimate'] ?? null) ? (int) $data['capacity_estimate'] : null,
            'sort' => (int) ($data['sort'] ?? 0),
            'signage' => filled($data['signage'] ?? null) ? trim((string) $data['signage']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParkingLots::route('/'),
        ];
    }
}
