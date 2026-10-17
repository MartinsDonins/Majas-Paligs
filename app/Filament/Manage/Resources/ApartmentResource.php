<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Filament\Manage\Resources\ApartmentResource\Pages;
use App\Models\Apartment;
use App\Models\Section;
use App\Services\Buildings\ApartmentMerger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Apartments (structure) with per-building overrides: merge two neighbouring cells into one apartment
 * (MVP-SPEC A.4 apartment_cells, AC 12). The series template is never touched.
 */
final class ApartmentResource extends Resource
{
    protected static ?string $model = Apartment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 21;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.structure');
    }

    public static function getModelLabel(): string
    {
        return __('admin.apartment.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.apartment.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('number')->label(__('admin.apartment.number'))->disabled(),
            TextInput::make('rooms_count')->label(__('admin.apartment.rooms'))->numeric()->minValue(1)->maxValue(9),
            TextInput::make('area_m2')->label(__('admin.apartment.area'))->numeric()->minValue(10)->maxValue(400),
            Textarea::make('override_note')->label(__('admin.apartment.override_note'))->maxLength(200),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('number')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('section'))
            ->columns([
                TextColumn::make('number')->label(__('admin.apartment.number'))->sortable()->searchable(),
                TextColumn::make('section.number')->label(__('admin.section.label')),
                TextColumn::make('floor')->label(__('admin.apartment.floor'))->sortable(),
                TextColumn::make('position')->label(__('admin.apartment.position')),
                TextColumn::make('side')->label(__('admin.apartment.side'))->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('rooms_count')->label(__('admin.apartment.rooms')),
                IconColumn::make('is_override')->label(__('admin.apartment.override'))->boolean(),
                TextColumn::make('retired_numbers')->label(__('admin.apartment.retired'))
                    ->formatStateUsing(fn (Apartment $record): string => implode(', ', $record->retired_numbers)),
                IconColumn::make('archived_at')->label(__('admin.apartment.archived'))->boolean()
                    ->getStateUsing(fn (Apartment $record): bool => $record->archived_at !== null),
            ])
            ->filters([
                SelectFilter::make('section')->label(__('admin.section.label'))
                    ->options(fn () => Section::query()->orderBy('number')->pluck('number', 'id')->map(fn ($n) => __('admin.section.short', ['n' => $n]))->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->where('section_id', (int) $data['value']) : $query),
                Filter::make('archived')->label(__('admin.apartment.archived'))
                    ->query(fn (Builder $query) => $query->whereNotNull('archived_at')),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('merge')
                    ->label(__('admin.apartment.merge'))
                    ->icon(Heroicon::OutlinedArrowsUpDown)
                    ->visible(fn (Apartment $record): bool => Gate::allows('merge', $record))
                    ->schema(fn (Apartment $record): array => [
                        Select::make('absorbed_id')
                            ->label(__('admin.apartment.merge_with'))
                            ->required()
                            ->options(fn (): array => self::neighbours($record)),
                        Textarea::make('note')->label(__('admin.apartment.override_note'))->maxLength(200),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.apartment.merge_help'))
                    ->action(function (Apartment $record, array $data): void {
                        Gate::authorize('merge', $record);
                        $absorbed = Apartment::query()->whereKey((int) $data['absorbed_id'])->firstOrFail();

                        try {
                            app(ApartmentMerger::class)->merge($record, $absorbed, $data['note'] ?? null);
                            Notification::make()->success()->title(__('admin.apartment.merged'))->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    /**
     * Direct neighbours (same stairwell; same floor ±1 position or same position ±1 floor), active only.
     *
     * @return array<int, string>
     */
    private static function neighbours(Apartment $apartment): array
    {
        return Apartment::query()->active()
            ->where('section_id', $apartment->section_id)
            ->where('id', '!=', $apartment->id)
            ->where(function (Builder $q) use ($apartment): void {
                $q->where(fn (Builder $w) => $w->where('floor', $apartment->floor)->whereIn('position', [$apartment->position - 1, $apartment->position + 1]))
                    ->orWhere(fn (Builder $w) => $w->where('position', $apartment->position)->whereIn('floor', [$apartment->floor - 1, $apartment->floor + 1]));
            })
            ->orderBy('number')
            ->pluck('number', 'id')
            ->map(fn ($n) => __('onboarding.role.apartment', ['n' => $n]))
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApartments::route('/'),
            'edit' => Pages\EditApartment::route('/{record}/edit'),
        ];
    }
}
