<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Audit\AuditLogger;
use App\Enums\DocumentKind;
use App\Filament\Platform\Resources\DocumentTemplateResource\Pages;
use App\Models\DocumentTemplate;
use BackedEnum;
use Database\Seeders\DocumentTemplateSeeder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Document masters (ROUND2-SPEC §3.3, GOV-011): DPA, DPA annex, building privacy notice, DPIA-lite, parking decision —
 * LV and RU, versioned. A published version is immutable (DB trigger + policy); "Jauna versija" copies it into a draft
 * (version + 1) that can be edited and published. Houses always start from the newest published version.
 * platform_admin only (DocumentTemplatePolicy); audited on the platform chain.
 */
final class DocumentTemplateResource extends Resource
{
    protected static ?string $model = DocumentTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?int $navigationSort = 43;

    protected static ?string $slug = 'document-templates';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.legal');
    }

    public static function getModelLabel(): string
    {
        return __('document.template.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('document.template.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label(__('document.template.title'))->required()->maxLength(200)->columnSpanFull(),
            Textarea::make('body')->label(__('document.template.body'))->required()->rows(24)->columnSpanFull()
                ->helperText(__('document.template.body_help')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('kind')
            ->columns([
                TextColumn::make('kind')->label(__('document.template.kind'))->formatStateUsing(fn (DocumentKind $state): string => $state->label()),
                TextColumn::make('locale')->label(__('document.template.locale'))->formatStateUsing(fn ($state): string => strtoupper($state->value)),
                TextColumn::make('version')->label(__('document.template.version'))->sortable(),
                TextColumn::make('title')->label(__('document.template.title'))->wrap(),
                TextColumn::make('published_at')->label(__('document.template.published'))
                    ->dateTime('d.m.Y', 'Europe/Riga')->placeholder(__('document.template.draft')),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (DocumentTemplate $record): bool => Gate::allows('update', $record)),
                Action::make('publish')
                    ->label(__('document.template.publish'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->requiresConfirmation()
                    ->modalDescription(__('document.template.publish_help'))
                    ->visible(fn (DocumentTemplate $record): bool => Gate::allows('publish', $record))
                    ->action(function (DocumentTemplate $record): void {
                        Gate::authorize('publish', $record);
                        try {
                            $attributes = DocumentTemplateSeeder::attributes($record->kind, $record->locale, $record->body);
                        } catch (InvalidArgumentException) {
                            Notification::make()->danger()->title(__('document.lint.markers_broken'))->send();

                            return;
                        }
                        $record->forceFill([
                            'required_markers' => $attributes['required_markers'],
                            'placeholders' => $attributes['placeholders'],
                            'published_at' => now(),
                        ])->save();
                        AuditLogger::record('document_template.publish', $record, data: ['kind' => $record->kind->value, 'version' => $record->version, 'locale' => $record->locale->value], platform: true, actorRole: 'platform_admin');
                        Notification::make()->success()->title(__('document.template.published_ok'))->send();
                    }),
                Action::make('newVersion')
                    ->label(__('document.template.new_version'))
                    ->icon(Heroicon::OutlinedDocumentPlus)
                    ->color('gray')
                    ->visible(fn (DocumentTemplate $record): bool => Gate::allows('create', DocumentTemplate::class) && $record->isPublished())
                    ->action(function (DocumentTemplate $record): void {
                        Gate::authorize('create', DocumentTemplate::class);
                        $next = (int) DocumentTemplate::query()->where('kind', $record->kind->value)->where('locale', $record->locale->value)->max('version') + 1;
                        $copy = DocumentTemplate::query()->create([
                            'kind' => $record->kind,
                            'version' => $next,
                            'locale' => $record->locale,
                            'title' => $record->title,
                            'body' => $record->body,
                            'required_markers' => $record->required_markers,
                            'placeholders' => $record->placeholders,
                            'covers_modules' => $record->covers_modules,
                            'created_by' => auth()->id(),
                        ]);
                        AuditLogger::record('document_template.create', $copy, data: ['kind' => $copy->kind->value, 'version' => $copy->version, 'locale' => $copy->locale->value], platform: true, actorRole: 'platform_admin');
                        Notification::make()->success()->title(__('document.template.created'))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentTemplates::route('/'),
            'edit' => Pages\EditDocumentTemplate::route('/{record}/edit'),
        ];
    }
}
