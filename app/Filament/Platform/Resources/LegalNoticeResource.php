<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\LegalNoticeResource\Pages;
use App\Models\LegalNotice;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Editable legal notices (e.g. "parking.riga.current_rule" banner), versioned by valid_from.
 */
final class LegalNoticeResource extends Resource
{
    protected static ?string $model = LegalNotice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 41;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.legal');
    }

    public static function getModelLabel(): string
    {
        return __('admin.legal.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.legal.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')->label(__('admin.legal.key'))->required()->maxLength(120),
            TextInput::make('jurisdiction')->label(__('admin.legal.jurisdiction'))->maxLength(60),
            DateTimePicker::make('valid_from')->label(__('admin.legal.valid_from'))->required(),
            TextInput::make('source_url')->label(__('admin.legal.source'))->url()->maxLength(500),
            Textarea::make('body.lv')->label('LV')->rows(6)->required()->columnSpanFull(),
            Textarea::make('body.ru')->label('RU')->rows(6)->required()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('valid_from', 'desc')
            ->columns([
                TextColumn::make('key')->label(__('admin.legal.key'))->searchable(),
                TextColumn::make('jurisdiction')->label(__('admin.legal.jurisdiction')),
                TextColumn::make('valid_from')->label(__('admin.legal.valid_from'))->dateTime('d.m.Y', 'Europe/Riga'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLegalNotices::route('/'),
            'create' => Pages\CreateLegalNotice::route('/create'),
            'edit' => Pages\EditLegalNotice::route('/{record}/edit'),
        ];
    }
}
