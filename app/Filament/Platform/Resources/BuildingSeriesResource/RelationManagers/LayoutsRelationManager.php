<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\BuildingSeriesResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Section layout presets (e.g. 2-2-3-3): positions with side and apartment type.
 */
final class LayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'sectionLayouts';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('admin.series.layout_code'))->required()->maxLength(20),
            TextInput::make('apartments_per_floor')->label(__('admin.series.apf'))->numeric()->required()->minValue(1)->maxValue(12),
            Toggle::make('is_default')->label(__('admin.series.default')),
            Repeater::make('positions')->label(__('admin.series.positions'))->columns(3)->schema([
                TextInput::make('position')->numeric()->required()->minValue(1),
                Select::make('side')->options(['left' => __('enum.side.left'), 'right' => __('enum.side.right')])->required(),
                TextInput::make('apartment_type_code')->label(__('admin.series.type_code'))->required(),
            ])->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                TextColumn::make('code')->label(__('admin.series.layout_code')),
                TextColumn::make('apartments_per_floor')->label(__('admin.series.apf')),
                IconColumn::make('is_default')->label(__('admin.series.default'))->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
