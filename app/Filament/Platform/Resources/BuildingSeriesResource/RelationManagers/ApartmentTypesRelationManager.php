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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Apartment types of the series with their rooms (codes kitchen|bath|wc|hall|room1..5, radiators, towel dryer).
 */
final class ApartmentTypesRelationManager extends RelationManager
{
    protected static string $relationship = 'apartmentTypes';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('admin.series.type_code'))->required()->maxLength(10),
            TextInput::make('rooms_count')->label(__('admin.apartment.rooms'))->numeric()->required()->minValue(1)->maxValue(9),
            TextInput::make('area_m2_typical')->label(__('admin.apartment.area'))->numeric(),
            TextInput::make('label.lv')->label(__('admin.series.type_label').' (LV)')->required(),
            TextInput::make('label.ru')->label(__('admin.series.type_label').' (RU)')->required(),
            Repeater::make('rooms')->relationship()->label(__('admin.series.rooms'))->addActionLabel(__('admin.series.add_room'))->columns(3)
                ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                    $data['label'] = is_string($data['label'] ?? null) ? (json_decode($data['label'], true) ?: []) : ($data['label'] ?? []);

                    return $data;
                })
                ->schema([
                    Select::make('code')->options(array_combine(
                        ['kitchen', 'bath', 'wc', 'hall', 'room1', 'room2', 'room3', 'room4', 'room5'],
                        ['kitchen', 'bath', 'wc', 'hall', 'room1', 'room2', 'room3', 'room4', 'room5'],
                    ))->required(),
                    TextInput::make('label.lv')->label('LV')->required(),
                    TextInput::make('label.ru')->label('RU')->required(),
                    TextInput::make('sort')->numeric()->default(0),
                    TextInput::make('radiator_count')->numeric()->default(1)->minValue(0)->maxValue(5),
                    Toggle::make('towel_dryer'),
                ])->columnSpanFull(),
        ]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.series.types');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                TextColumn::make('code')->label(__('admin.series.type_code')),
                TextColumn::make('label')->label(__('admin.series.type_label')),
                TextColumn::make('rooms_count')->label(__('admin.apartment.rooms')),
                TextColumn::make('area_m2_typical')->label(__('admin.apartment.area')),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                EditAction::make()->mutateRecordDataUsing(function (array $data, $record): array {
                    $data['label'] = $record->getTranslations('label');

                    return $data;
                }),
                DeleteAction::make(),
            ]);
    }
}
