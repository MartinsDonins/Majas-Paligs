<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Audit\AuditLogger;
use App\Enums\PrivacyNoticeKind;
use App\Filament\Platform\Resources\PrivacyNoticeResource\Pages;
use App\Models\PrivacyNoticeVersion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Privacy notice texts: the platform account notice (CoreDigify = controller) and building templates the
 * houses adopt ({controller.name}, {controller.contact}). A published version is immutable (edit = new version).
 */
final class PrivacyNoticeResource extends Resource
{
    protected static ?string $model = PrivacyNoticeVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.legal');
    }

    public static function getModelLabel(): string
    {
        return __('admin.privacy_notice.label');
    }

    /**
     * A published version is immutable (edit = new version): its row is not an edit link (it opened a 403).
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof PrivacyNoticeVersion && $record->published_at === null && parent::canEdit($record);
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.privacy_notice.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')->label(__('admin.privacy_notice.kind'))->options(PrivacyNoticeKind::options())->required(),
            TextInput::make('version')->label(__('admin.privacy_notice.version'))->required()->maxLength(40),
            CheckboxList::make('covers_modules')->label(__('admin.privacy_notice.covers'))
                ->options(collect(['account', 'core', 'heating', 'outages', 'parking'])->mapWithKeys(fn ($m) => [$m => __('enum.module.'.$m)])->all())
                ->columns(5),
            Textarea::make('body.lv')->label('LV')->rows(14)->required()->columnSpanFull(),
            Textarea::make('body.ru')->label('RU')->rows(14)->required()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('kind')->label(__('admin.privacy_notice.kind'))->formatStateUsing(fn (PrivacyNoticeKind $state) => $state->label()),
                TextColumn::make('version')->label(__('admin.privacy_notice.version')),
                // The state is an array: Filament formats EACH item, so implode() here printed the list N times.
                TextColumn::make('covers_modules')->label(__('admin.privacy_notice.covers'))
                    ->formatStateUsing(fn (string $state): string => __('enum.module.'.$state))
                    ->separator(', '),
                TextColumn::make('published_at')->label(__('admin.privacy_notice.published'))->dateTime('d.m.Y H:i', 'Europe/Riga'),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (PrivacyNoticeVersion $record): bool => $record->published_at === null),
                Action::make('publish')
                    ->label(__('admin.notice.publish'))
                    ->visible(fn (PrivacyNoticeVersion $record): bool => $record->published_at === null)
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.privacy_notice.publish_help'))
                    ->action(function (PrivacyNoticeVersion $record): void {
                        $record->forceFill(['published_at' => now()])->save();
                        AuditLogger::record('privacy_notice.template_publish', $record, data: ['kind' => $record->kind->value, 'version' => $record->version], platform: true, actorRole: 'platform_admin');
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPrivacyNotices::route('/'),
            'create' => Pages\CreatePrivacyNotice::route('/create'),
            'edit' => Pages\EditPrivacyNotice::route('/{record}/edit'),
        ];
    }
}
