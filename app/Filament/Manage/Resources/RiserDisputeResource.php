<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Enums\DisputeStatus;
use App\Filament\Manage\Resources\RiserDisputeResource\Pages;
use App\Models\RiserDispute;
use App\Models\User;
use App\Services\Heating\RiserLabel;
use App\Services\Heating\RiserReviewService;
use App\Tenancy\CurrentBuilding;
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
 * "Mans radiators nav šajā stāvadā" disputes (HEAT-007). building_admin resolves (links corrected in Risers) or
 * rejects (links right — raises a hypothesis riser to resident_confirmed). Elders see their own stairwells.
 */
final class RiserDisputeResource extends Resource
{
    protected static ?string $model = RiserDispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?int $navigationSort = 43;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.heating');
    }

    public static function getModelLabel(): string
    {
        return __('admin.dispute.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.dispute.plural');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', RiserDispute::class);
    }

    public static function getNavigationBadge(): ?string
    {
        /** @var Builder<RiserDispute> $query */
        $query = self::getEloquentQuery();
        $count = $query->open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['riser:id,code,section_id,confidence', 'riser.section:id,number', 'apartment:id,number']))
            ->columns([
                TextColumn::make('riser.code')->label(__('admin.riser.code'))
                    ->description(fn (RiserDispute $record): string => RiserLabel::for($record->riser->code)),
                TextColumn::make('riser.section.number')->label(__('admin.section.label')),
                TextColumn::make('apartment.number')->label(__('admin.apartment.number')),
                TextColumn::make('status')->label(__('admin.dispute.status'))
                    ->formatStateUsing(fn (DisputeStatus $state) => $state->label())
                    ->badge()
                    ->color(fn (DisputeStatus $state) => $state === DisputeStatus::Open ? 'warning' : 'gray'),
                TextColumn::make('created_at')->label(__('admin.dispute.created'))->dateTime('d.m.Y H:i', 'Europe/Riga')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.dispute.status'))->options(DisputeStatus::options())->default('open'),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->label(__('admin.dispute.resolve'))
                    ->color('success')
                    ->visible(fn (RiserDispute $record): bool => Gate::allows('resolve', $record))
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.dispute.resolve_help'))
                    ->action(function (RiserDispute $record): void {
                        app(RiserReviewService::class)->decide(self::actor(), $record, DisputeStatus::Resolved);
                        Notification::make()->success()->title(__('admin.dispute.saved'))->send();
                    }),
                Action::make('reject')
                    ->label(__('admin.dispute.reject'))
                    ->color('gray')
                    ->visible(fn (RiserDispute $record): bool => Gate::allows('resolve', $record))
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.dispute.reject_help'))
                    ->action(function (RiserDispute $record): void {
                        app(RiserReviewService::class)->decide(self::actor(), $record, DisputeStatus::Rejected);
                        Notification::make()->success()->title(__('admin.dispute.saved'))->send();
                    }),
            ]);
    }

    private static function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * Elders: disputes of their own stairwells only.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = self::actor();
        $building = CurrentBuilding::get();

        return $user->isBuildingAdmin($building)
            ? $query
            : $query->whereHas('riser', fn (Builder $q) => $q->whereIn('section_id', $user->elderSectionIds($building)));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRiserDisputes::route('/'),
        ];
    }
}
