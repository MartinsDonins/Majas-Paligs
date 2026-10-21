<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\BuildingSeriesResource\RelationManagers;

use App\Enums\RiserConfidence;
use App\Enums\RiserKind;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Riser templates: code pattern ({section}, {position}, {side}, {room}), kind, served positions/rooms
 * ("*" = every room with a radiator), confidence + source.
 */
final class RisersRelationManager extends RelationManager
{
    protected static string $relationship = 'risers';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code_pattern')->label(__('admin.series.code_pattern'))->required()->maxLength(60),
            Select::make('kind')->label(__('admin.riser.kind'))->options(RiserKind::options())->required(),
            Select::make('section_layout_id')->label(__('admin.series.layout_code'))
                ->relationship('sectionLayout', 'code', fn ($query) => $query->where('series_id', $this->getOwnerRecord()->getKey())),
            Select::make('confidence')->label(__('admin.riser.confidence'))->options(RiserConfidence::options())->required()->default('hypothesis'),
            Repeater::make('serves')->label(__('admin.series.serves'))->addActionLabel(__('admin.series.add_serve'))->columns(2)->schema([
                TextInput::make('position')->numeric()->required()->minValue(1),
                TextInput::make('room_code')->label(__('admin.series.room_code')),
            ])->columnSpanFull(),
            Textarea::make('source_ref')->label(__('admin.riser.source'))->columnSpanFull(),
        ]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.series.risers');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code_pattern')
            ->columns([
                TextColumn::make('code_pattern')->label(__('admin.series.code_pattern')),
                TextColumn::make('kind')->label(__('admin.riser.kind'))->formatStateUsing(fn (RiserKind $state) => $state->label()),
                TextColumn::make('confidence')->label(__('admin.riser.confidence'))->formatStateUsing(fn (RiserConfidence $state) => $state->label())->badge(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
