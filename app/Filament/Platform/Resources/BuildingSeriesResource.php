<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Enums\SeriesStatus;
use App\Filament\Platform\Resources\BuildingSeriesResource\Pages;
use App\Filament\Platform\Resources\BuildingSeriesResource\RelationManagers;
use App\Models\BuildingSeries;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Building series catalogue (SERIES-001) — platform data: series, layout presets, apartment types (+ rooms),
 * riser templates with confidence and sources.
 */
final class BuildingSeriesResource extends Resource
{
    protected static ?string $model = BuildingSeries::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('admin.series.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.series.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('code')->label(__('admin.series.code'))->required()->maxLength(20)->unique(ignoreRecord: true),
                Select::make('status')->label(__('admin.series.status'))->options(SeriesStatus::options())->required(),
                TextInput::make('name.lv')->label(__('admin.series.name').' (LV)')->required(),
                TextInput::make('name.ru')->label(__('admin.series.name').' (RU)')->required(),
                TagsInput::make('aliases')->label(__('admin.series.aliases')),
                TagsInput::make('floors_typical')->label(__('admin.series.floors_typical')),
                TextInput::make('years_from')->label(__('admin.series.years_from'))->numeric(),
                TextInput::make('years_to')->label(__('admin.series.years_to'))->numeric(),
                TextInput::make('wall_material')->label(__('admin.series.wall')),
                TextInput::make('apartments_per_floor_typical')->label(__('admin.series.apf'))->numeric(),
                Textarea::make('notes.lv')->label(__('admin.series.notes').' (LV)'),
                Textarea::make('notes.ru')->label(__('admin.series.notes').' (RU)'),
            ]),
            Section::make(__('admin.series.sources'))->schema([
                Repeater::make('sources')->hiddenLabel()->columns(3)->defaultItems(0)->addActionLabel(__('admin.series.add_source'))->schema([
                    TextInput::make('title')->label(__('admin.series.source_title'))->required(),
                    TextInput::make('url')->label('URL')->url(),
                    DatePicker::make('accessed_at')->label(__('admin.series.accessed_at')),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label(__('admin.series.code'))->searchable()->sortable(),
                TextColumn::make('name')->label(__('admin.series.name')),
                TextColumn::make('status')->label(__('admin.series.status'))->formatStateUsing(fn (SeriesStatus $state) => $state->label())->badge(),
                TextColumn::make('section_layouts_count')->label(__('admin.series.layouts'))->counts('sectionLayouts'),
                TextColumn::make('risers_count')->label(__('admin.series.risers'))->counts('risers'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LayoutsRelationManager::class,
            RelationManagers\ApartmentTypesRelationManager::class,
            RelationManagers\RisersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBuildingSeries::route('/'),
            'create' => Pages\CreateBuildingSeries::route('/create'),
            'edit' => Pages\EditBuildingSeries::route('/{record}/edit'),
        ];
    }
}
