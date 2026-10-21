<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Enums\ParkingExportFormat;
use App\Enums\ParkingExportKind;
use App\Filament\Manage\Resources\Concerns\ParkingStaff;
use App\Filament\Manage\Resources\ParkingExportResource\Pages;
use App\Models\ParkingExport;
use App\Services\Parking\ParkingActionFailed;
use App\Services\Parking\ParkingExportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * Police / manager lists (PARK-005): request (building_admin) → approve (another building_admin or the parking
 * officer — four eyes) → download through a 10-minute signed URL. Files are generated on demand and never stored;
 * this list holds metadata only (number, kind, who/when by role, row count). "Caurlaižu saraksts" columns per kind
 * (PARK-013): the manager list never has plates and has apartment numbers only when asked for after the house adopted
 * parking rules (`include_apartments`, building_admin).
 */
final class ParkingExportResource extends Resource
{
    use ParkingStaff;

    protected static ?string $model = ParkingExport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static ?int $navigationSort = 55;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.parking');
    }

    public static function getModelLabel(): string
    {
        return __('admin.parking.export.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.parking.export.plural');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', ParkingExport::class);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('requested_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('admin.parking.export.number'))->fontFamily('mono'),
                TextColumn::make('kind')->label(__('admin.parking.export.kind'))->badge()->formatStateUsing(fn (ParkingExportKind $state) => $state->label()),
                TextColumn::make('format')->label(__('admin.parking.export.format'))->formatStateUsing(fn (ParkingExportFormat $state) => strtoupper($state->value)),
                TextColumn::make('include_apartments')->label(__('admin.parking.export.apartments'))
                    ->formatStateUsing(fn (bool $state): string => $state ? __('admin.parking.export.apartments_yes') : __('admin.parking.export.apartments_no')),
                TextColumn::make('requested_role')->label(__('admin.parking.export.requested'))
                    ->formatStateUsing(fn (?string $state, ParkingExport $record) => ($state !== null ? __('enum.building_role.'.$state) : '').' · '.$record->requested_at->setTimezone('Europe/Riga')->format('d.m.Y H:i')),
                TextColumn::make('approved_role')->label(__('admin.parking.export.approved'))->placeholder(__('admin.parking.export.waiting'))
                    ->formatStateUsing(fn (?string $state, ParkingExport $record) => $state !== null ? __('enum.building_role.'.$state).' · '.$record->approved_at?->setTimezone('Europe/Riga')->format('d.m.Y H:i') : ''),
                TextColumn::make('generated_at')->label(__('admin.parking.export.generated'))->dateTime('d.m.Y H:i', 'Europe/Riga')->placeholder('—'),
                TextColumn::make('row_count')->label(__('admin.parking.export.rows'))->placeholder('—'),
                TextColumn::make('expires_at')->label(__('admin.parking.export.expires'))->dateTime('d.m.Y', 'Europe/Riga'),
            ])
            ->headerActions([
                Action::make('request')
                    ->label(__('admin.parking.export.request'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => Gate::allows('create', ParkingExport::class))
                    ->schema([
                        Select::make('kind')->label(__('admin.parking.export.kind'))->required()
                            ->options(fn (): array => array_filter(ParkingExportKind::options(), fn (string $k): bool => $k !== 'police' || ParkingExportService::policeEnabled(), ARRAY_FILTER_USE_KEY))
                            ->helperText(__('admin.parking.export.police_help'))
                            ->live(),
                        Toggle::make('include_apartments')->label(__('admin.parking.export.include_apartments'))
                            ->helperText(__('admin.parking.export.include_apartments_help'))
                            ->visible(fn (Get $get): bool => $get('kind') === 'manager' && Gate::allows('includeApartments', ParkingExport::class)),
                        Select::make('format')->label(__('admin.parking.export.format'))->options(['csv' => 'CSV', 'pdf' => 'PDF'])->default('csv')->required(),
                    ])
                    ->action(function (array $data): void {
                        Gate::authorize('create', ParkingExport::class);
                        try {
                            $kind = ParkingExportKind::from((string) $data['kind']);
                            $withApartments = $kind === ParkingExportKind::Manager && (bool) ($data['include_apartments'] ?? false);
                            if ($withApartments) {
                                Gate::authorize('includeApartments', ParkingExport::class);
                            }
                            $export = app(ParkingExportService::class)->request(self::actor(), $kind, ParkingExportFormat::from((string) $data['format']), $withApartments);
                        } catch (ParkingActionFailed $e) {
                            Notification::make()->danger()->title($e->label())->send();

                            return;
                        }
                        Notification::make()->success()->title(__('admin.parking.export.requested_title', ['number' => $export->number]))->send();
                    }),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('admin.parking.export.approve'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ParkingExport $record): bool => Gate::allows('approve', $record))
                    ->action(function (ParkingExport $record): void {
                        Gate::authorize('approve', $record);
                        app(ParkingExportService::class)->approve(self::actor(), $record);
                        Notification::make()->success()->title(__('admin.parking.saved'))->send();
                    }),
                Action::make('download')
                    ->label(__('admin.parking.export.download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (ParkingExport $record): bool => Gate::allows('download', $record))
                    ->url(fn (ParkingExport $record): string => ParkingExportService::downloadUrl($record))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParkingExports::route('/'),
        ];
    }
}
