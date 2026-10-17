<?php

declare(strict_types=1);

namespace App\Filament\Manage\Resources;

use App\Audit\AuditChainVerifier;
use App\Filament\Manage\Resources\AuditEntryResource\Pages;
use App\Models\AuditEntry;
use App\Tenancy\CurrentBuilding;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Building audit log (read-only, building chain only) + chain verification. Actors by role; no PII in data.
 */
final class AuditEntryResource extends Resource
{
    protected static ?string $model = AuditEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 90;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.governance');
    }

    public static function getModelLabel(): string
    {
        return __('admin.audit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.audit.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return AuditEntry::query()->forBuilding(CurrentBuilding::id()); // @phpstan-ignore return.type
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('admin.audit.time'))->dateTime('d.m.Y H:i:s', 'Europe/Riga'),
                TextColumn::make('action')->label(__('admin.audit.action'))->formatStateUsing(fn (string $state) => __('audit.action.'.$state)),
                TextColumn::make('actor_role')->label(__('admin.audit.actor'))->formatStateUsing(fn (?string $state) => __('audit.actor.'.($state ?? 'system'))),
                TextColumn::make('subject_type')->label(__('admin.audit.subject')),
                TextColumn::make('reason_code')->label(__('admin.audit.reason')),
                TextColumn::make('hash')->label('#')->formatStateUsing(fn ($state) => is_resource($state) ? substr(bin2hex((string) stream_get_contents($state)), 0, 10) : substr(bin2hex((string) $state), 0, 10)),
            ])
            ->filters([
                SelectFilter::make('action')->label(__('admin.audit.action'))
                    ->options(fn () => AuditEntry::query()->forBuilding(CurrentBuilding::id())->distinct()->orderBy('action')->pluck('action', 'action')->map(fn ($a) => __('audit.action.'.$a))->all()),
            ])
            ->headerActions([
                Action::make('verify')
                    ->label(__('admin.audit.verify'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->action(function (): void {
                        $result = app(AuditChainVerifier::class)->verify(CurrentBuilding::id());
                        $result->valid
                            ? Notification::make()->success()->title(__('admin.audit.valid', ['count' => $result->checked]))->send()
                            : Notification::make()->danger()->title(__('admin.audit.invalid', ['id' => $result->failedAtId, 'reason' => $result->reason]))->persistent()->send();
                    }),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditEntries::route('/'),
        ];
    }
}
