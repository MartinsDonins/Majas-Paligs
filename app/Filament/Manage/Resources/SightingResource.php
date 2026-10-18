<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Casts\Bytea;
use App\Enums\SightingStatus;
use App\Filament\Manage\Resources\SightingResource\Pages;
use App\Models\PlateSightingStat;
use App\Models\Sighting;
use App\Models\User;
use App\Services\Parking\SightingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Unknown-car reports (PARK-003) — parking_officer / building_admin only (L1): plate group ref (no plate data), lot, time,
 * matched_registered, repeat flag (≥ 2 independent apartments in 30 days), photo via a 5-minute signed URL,
 * dismiss / resolve. No reporter identity is shown. Global search is off.
 */
final class SightingResource extends Resource
{
    protected static ?string $model = Sighting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?int $navigationSort = 53;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.parking');
    }

    public static function getModelLabel(): string
    {
        return __('admin.parking.sighting.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.parking.sighting.plural');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', Sighting::class);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('seen_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('lot:id,name'))
            ->columns([
                TextColumn::make('seen_at')->label(__('admin.parking.sighting.seen'))->dateTime('d.m.Y H:i', 'Europe/Riga')->sortable(),
                TextColumn::make('lot.name')->label(__('admin.parking.lot.label')),
                // No plate data (review fix): a short group ref from a hash of the per-building blind index — the same
                // plate gets the same ref, so repeats can be grouped, but it can never be matched to the registry by eye.
                TextColumn::make('plate_group')->label(__('admin.parking.sighting.group'))->fontFamily('mono')
                    ->getStateUsing(fn (Sighting $record): string => self::groupRef($record)),
                IconColumn::make('matched_registered')->label(__('admin.parking.sighting.registered'))->boolean(),
                IconColumn::make('repeat')->label(__('admin.parking.sighting.repeat'))->boolean()
                    ->getStateUsing(fn (Sighting $record): bool => PlateSightingStat::query()
                        ->where('plate_bidx', Bytea::literal($record->plate_bidx))->where('is_repeat', true)->exists())
                    ->trueIcon(Heroicon::OutlinedArrowPathRoundedSquare)->trueColor('warning')->falseIcon(null),
                IconColumn::make('photo')->label(__('admin.parking.sighting.photo'))->boolean()
                    ->getStateUsing(fn (Sighting $record): bool => $record->hasPhoto())->falseIcon(null),
                TextColumn::make('status')->label(__('admin.parking.sighting.status'))->badge()
                    ->formatStateUsing(fn (SightingStatus $state) => $state->label())
                    ->color(fn (SightingStatus $state) => match ($state) {
                        SightingStatus::Open => 'warning',
                        SightingStatus::Disputed => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.parking.sighting.status'))->options(SightingStatus::options()),
            ])
            ->recordActions([
                Action::make('photo')
                    ->label(__('admin.parking.sighting.photo'))
                    ->icon(Heroicon::OutlinedPhoto)
                    ->visible(fn (Sighting $record): bool => $record->hasPhoto() && Gate::allows('photo', $record))
                    ->url(fn (Sighting $record): string => SightingService::photoUrl($record))
                    ->openUrlInNewTab(),
                Action::make('resolve')
                    ->label(__('admin.parking.sighting.resolve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Sighting $record): bool => in_array($record->status, [SightingStatus::Open, SightingStatus::Disputed], true) && Gate::allows('update', $record))
                    ->action(fn (Sighting $record) => self::handle($record, SightingStatus::Resolved)),
                Action::make('dismiss')
                    ->label(__('admin.parking.sighting.dismiss'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (Sighting $record): bool => in_array($record->status, [SightingStatus::Open, SightingStatus::Disputed], true) && Gate::allows('update', $record))
                    ->action(fn (Sighting $record) => self::handle($record, SightingStatus::Dismissed)),
            ]);
    }

    public static function groupRef(Sighting $sighting): string
    {
        $ref = strtoupper(substr(hash('sha256', 'sighting-group:'.$sighting->building_id.':'.bin2hex((string) $sighting->plate_bidx)), 0, 4));

        return __('admin.parking.sighting.group_ref', ['ref' => $ref]);
    }

    private static function handle(Sighting $sighting, SightingStatus $status): void
    {
        Gate::authorize('update', $sighting);
        /** @var User $user */
        $user = auth()->user();
        app(SightingService::class)->handle($user, $sighting, $status);
        Notification::make()->success()->title(__('admin.parking.saved'))->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSightings::route('/'),
        ];
    }
}
