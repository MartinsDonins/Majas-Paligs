<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Filament\Manage\Resources\SectionResource\Pages;
use App\Models\Section;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Stairwells of the current building (read-only: structure comes from the generator).
 */
final class SectionResource extends Resource
{
    protected static ?string $model = Section::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 20;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.structure');
    }

    public static function getModelLabel(): string
    {
        return __('admin.section.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.section.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('number')
            ->columns([
                TextColumn::make('number')->label(__('admin.section.number'))->sortable(),
                TextColumn::make('first_apartment_no')->label(__('admin.section.range'))
                    ->formatStateUsing(fn (Section $record): string => $record->first_apartment_no.'–'.$record->lastApartmentNo()),
                TextColumn::make('floors')->label(__('admin.section.floors')),
                TextColumn::make('apartments_per_floor')->label(__('admin.section.per_floor')),
                TextColumn::make('layout_code')->label(__('admin.section.layout'))->badge(),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSections::route('/'),
        ];
    }
}
