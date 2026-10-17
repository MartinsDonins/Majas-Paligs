<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Enums\RiserConfidence;
use App\Enums\RiserKind;
use App\Filament\Manage\Resources\RiserResource\Pages;
use App\Models\Riser;
use App\Models\Section;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Risers with confidence (hypothesis → resident/manager/drawing confirmed) and their links.
 */
final class RiserResource extends Resource
{
    protected static ?string $model = Riser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static ?int $navigationSort = 22;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.structure');
    }

    public static function getModelLabel(): string
    {
        return __('admin.riser.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.riser.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('confidence')->label(__('admin.riser.confidence'))->options(RiserConfidence::options())->required(),
            Textarea::make('source_ref')->label(__('admin.riser.source'))->maxLength(500),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('section')->withCount('links'))
            ->columns([
                TextColumn::make('code')->label(__('admin.riser.code'))->searchable()->sortable(),
                TextColumn::make('kind')->label(__('admin.riser.kind'))->formatStateUsing(fn (RiserKind $state) => $state->label())->badge(),
                TextColumn::make('section.number')->label(__('admin.section.label')),
                TextColumn::make('side')->label(__('admin.apartment.side'))->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('links_count')->label(__('admin.riser.links')),
                TextColumn::make('confidence')->label(__('admin.riser.confidence'))
                    ->formatStateUsing(fn (RiserConfidence $state) => $state->label())
                    ->badge()
                    ->color(fn (RiserConfidence $state) => $state === RiserConfidence::Hypothesis ? 'warning' : 'success'),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('admin.riser.kind'))->options(RiserKind::options()),
                SelectFilter::make('section_id')->label(__('admin.section.label'))
                    ->options(fn () => Section::query()->orderBy('number')->pluck('number', 'id')->map(fn ($n) => __('admin.section.short', ['n' => $n]))->all()),
                SelectFilter::make('confidence')->label(__('admin.riser.confidence'))->options(RiserConfidence::options()),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRisers::route('/'),
            'edit' => Pages\EditRiser::route('/{record}/edit'),
        ];
    }
}
