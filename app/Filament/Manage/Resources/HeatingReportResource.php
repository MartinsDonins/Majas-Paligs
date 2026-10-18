<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Enums\HeatingStatus;
use App\Enums\HeatingTriage;
use App\Enums\ReportSource;
use App\Filament\Manage\Resources\HeatingReportResource\Pages;
use App\Models\Apartment;
use App\Models\HeatingReport;
use App\Models\User;
use App\Services\Heating\HeatingReportService;
use App\Services\Heating\RiserLabel;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Per-apartment heating reports (staff only, HeatingReportPolicy::viewApartments): building_admin all stairwells,
 * section_elder only their own. Shows the private note (never exported). building_admin may hide a report
 * (excluded from every aggregate, audit-logged). Reporter identities are never shown.
 */
final class HeatingReportResource extends Resource
{
    protected static ?string $model = HeatingReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static ?int $navigationSort = 39;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.heating');
    }

    public static function getModelLabel(): string
    {
        return __('admin.heating_report.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.heating_report.plural');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', HeatingReport::class);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('reported_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['apartment:id,number,floor,section_id', 'apartment.section:id,number', 'room:id,code,label']))
            ->columns([
                TextColumn::make('apartment.number')->label(__('admin.apartment.number'))->sortable(),
                TextColumn::make('apartment.section.number')->label(__('admin.section.label')),
                TextColumn::make('apartment.floor')->label(__('admin.apartment.floor')),
                TextColumn::make('room')->label(__('admin.heating_report.room'))
                    ->getStateUsing(fn (HeatingReport $record): string => (string) $record->room->getTranslation('label', app()->getLocale()) ?: RiserLabel::room($record->room->code)),
                TextColumn::make('status')->label(__('admin.heating_report.status'))
                    ->formatStateUsing(fn (HeatingStatus $state) => $state->label())
                    ->badge()
                    ->color(fn (HeatingStatus $state) => match ($state) {
                        HeatingStatus::Cold => 'info',
                        HeatingStatus::Lukewarm => 'warning',
                        HeatingStatus::Warm => 'success',
                        HeatingStatus::Overheated => 'danger',
                    }),
                TextColumn::make('temperature_c')->label('°C'),
                TextColumn::make('triage')->label(__('admin.heating_report.triage'))->formatStateUsing(fn (?HeatingTriage $state) => $state?->label()),
                TextColumn::make('note_private')->label(__('admin.heating_report.note'))->limit(60)->wrap(),
                TextColumn::make('source')->label(__('admin.heating_report.source'))->formatStateUsing(fn (ReportSource $state) => $state->label()),
                TextColumn::make('reported_at')->label(__('admin.heating_report.reported'))->dateTime('d.m.Y H:i', 'Europe/Riga')->sortable(),
                TextColumn::make('hidden_at')->label(__('admin.heating_report.hidden'))->dateTime('d.m.Y', 'Europe/Riga'),
            ])
            ->filters([
                TernaryFilter::make('current')->label(__('admin.heating_report.current'))->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->whereNull('superseded_at')->whereNull('hidden_at'),
                        false: fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNotNull('superseded_at')->orWhereNotNull('hidden_at')),
                        blank: fn (Builder $q) => $q,
                    ),
                SelectFilter::make('status')->label(__('admin.heating_report.status'))->options(HeatingStatus::options()),
            ])
            ->recordActions([
                Action::make('hide')
                    ->label(__('admin.heating_report.hide'))
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('danger')
                    ->visible(fn (HeatingReport $record): bool => $record->hidden_at === null && Gate::allows('hide', $record))
                    ->schema([TextInput::make('reason')->label(__('admin.heating_report.hide_reason'))->required()->maxLength(200)])
                    ->action(function (HeatingReport $record, array $data): void {
                        /** @var User $user */
                        $user = auth()->user();
                        app(HeatingReportService::class)->hide($user, $record, (string) $data['reason']);
                        Notification::make()->success()->title(__('admin.heating_report.hidden_done'))->send();
                    }),
            ]);
    }

    /**
     * Staff scope: building_admin all; elder only apartments of their own stairwells.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        /** @var User $user */
        $user = auth()->user();
        $building = CurrentBuilding::get();

        return $user->isBuildingAdmin($building)
            ? $query
            : $query->whereIn('apartment_id', Apartment::query()->whereIn('section_id', $user->elderSectionIds($building))->select('id'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHeatingReports::route('/'),
        ];
    }
}
