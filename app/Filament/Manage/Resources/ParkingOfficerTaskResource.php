<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Audit\AuditLogger;
use App\Enums\OfficerTaskKind;
use App\Enums\OfficerTaskStatus;
use App\Enums\VehicleStatus;
use App\Filament\Manage\Resources\Concerns\ParkingStaff;
use App\Filament\Manage\Resources\ParkingOfficerTaskResource\Pages;
use App\Models\ParkingOfficerTask;
use App\Models\Vehicle;
use App\Services\Parking\ParkingActionFailed;
use App\Services\Parking\VehicleRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Parking officer queue (PARK-001.3): "Caurlaide bez auto", "Numurs jau reģistrēts citā dzīvoklī — pārbaudīt",
 * vehicles to verify (declarations, plate changes). Ids + masked plates only. Verify activates the vehicle (and
 * replaces the main permit for a plate change); done / dismiss close the task.
 */
final class ParkingOfficerTaskResource extends Resource
{
    use ParkingStaff;

    protected static ?string $model = ParkingOfficerTask::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?int $navigationSort = 49;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.parking');
    }

    public static function getModelLabel(): string
    {
        return __('admin.parking.task.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.parking.task.plural');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', ParkingOfficerTask::class);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ParkingOfficerTask::query()->open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['permit:id,number,apartment_id', 'permit.apartment:id,number', 'vehicle', 'vehicle.apartment:id,number']))
            ->columns([
                TextColumn::make('kind')->label(__('admin.parking.task.kind'))->badge()
                    ->formatStateUsing(fn (OfficerTaskKind $state) => $state->label())
                    ->color(fn (OfficerTaskKind $state) => $state === OfficerTaskKind::VehicleConflict ? 'warning' : 'info'),
                TextColumn::make('apartment')->label(__('admin.apartment.number'))
                    ->getStateUsing(fn (ParkingOfficerTask $record) => $record->vehicle?->apartment->number ?? $record->permit?->apartment->number),
                TextColumn::make('permit.number')->label(__('admin.parking.permit.number'))->fontFamily('mono')->placeholder('—'),
                TextColumn::make('plate')->label(__('admin.parking.vehicle.plate'))->fontFamily('mono')
                    ->getStateUsing(fn (ParkingOfficerTask $record): string => self::maskedPlate($record->vehicle)),
                TextColumn::make('status')->label(__('admin.parking.task.status'))->badge()->formatStateUsing(fn (OfficerTaskStatus $state) => $state->label()),
                TextColumn::make('created_at')->label(__('admin.parking.task.created'))->dateTime('d.m.Y H:i', 'Europe/Riga')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.parking.task.status'))->options(OfficerTaskStatus::options())->default('open'),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label(__('admin.parking.vehicle.verify'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->visible(fn (ParkingOfficerTask $record): bool => $record->status === OfficerTaskStatus::Open
                        && $record->vehicle?->status === VehicleStatus::Pending && Gate::allows('verify', $record->vehicle))
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.parking.vehicle.verify_help'))
                    ->action(function (ParkingOfficerTask $record): void {
                        $vehicle = Vehicle::query()->findOrFail((string) $record->vehicle_id);
                        Gate::authorize('verify', $vehicle);
                        try {
                            app(VehicleRegistry::class)->activate(self::actor(), $vehicle);
                        } catch (ParkingActionFailed $e) {
                            Notification::make()->danger()->title($e->label())->send();

                            return;
                        }
                        Notification::make()->success()->title(__('admin.parking.vehicle.verified'))->send();
                    }),
                Action::make('done')
                    ->label(__('admin.parking.task.done'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (ParkingOfficerTask $record): bool => $record->status === OfficerTaskStatus::Open && Gate::allows('update', $record))
                    ->action(fn (ParkingOfficerTask $record) => self::close($record, OfficerTaskStatus::Done)),
                Action::make('dismiss')
                    ->label(__('admin.parking.task.dismiss'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (ParkingOfficerTask $record): bool => $record->status === OfficerTaskStatus::Open && Gate::allows('update', $record))
                    ->action(fn (ParkingOfficerTask $record) => self::close($record, OfficerTaskStatus::Dismissed)),
            ]);
    }

    private static function close(ParkingOfficerTask $task, OfficerTaskStatus $status): void
    {
        Gate::authorize('update', $task);
        $task->forceFill(['status' => $status, 'done_by' => self::actor()->id, 'done_at' => now()])->save();
        AuditLogger::record('parking_task.close', $task, data: ['status' => $status->value]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParkingOfficerTasks::route('/'),
        ];
    }
}
