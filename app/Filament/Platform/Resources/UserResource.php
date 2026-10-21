<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Audit\AuditLogger;
use App\Auth\StepUpService;
use App\Enums\PlatformRole;
use App\Filament\Platform\Resources\UserResource\Pages;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Minimal platform user administration (MVP-SPEC A.8): lock/unlock PIN login, platform role. No residents'
 * personal profile editing; deletion is the users' own self-service tombstone.
 */
final class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string
    {
        return __('admin.nav.platform');
    }

    public static function getModelLabel(): string
    {
        return __('admin.user.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.user.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('email')->label(__('auth.email.label'))->searchable()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : self::mask($state)),
                IconColumn::make('login_handle')->label('PIN')->boolean()->getStateUsing(fn (User $r): bool => $r->login_handle !== null),
                TextColumn::make('platform_role')->label(__('admin.user.platform_role'))->formatStateUsing(fn ($state) => $state?->label())->badge(),
                IconColumn::make('pin_locked_until')->label(__('admin.user.locked'))->boolean()
                    ->getStateUsing(fn (User $r): bool => $r->pin_locked_until?->isFuture() ?? false),
                TextColumn::make('last_login_at')->label(__('admin.user.last_login'))->dateTime('d.m.Y H:i', 'Europe/Riga'),
                IconColumn::make('anonymised_at')->label(__('admin.user.deleted'))->boolean()->getStateUsing(fn (User $r): bool => $r->isTombstone()),
            ])
            ->recordActions([
                Action::make('lock')
                    ->label(fn (User $r): string => $r->pin_locked_until?->isFuture() ? __('admin.user.unlock') : __('admin.user.lock'))
                    ->visible(fn (User $r): bool => $r->login_handle !== null && Gate::allows('update', $r))
                    ->requiresConfirmation()
                    ->action(function (User $r): void {
                        $locked = $r->pin_locked_until?->isFuture() ?? false;
                        $r->forceFill(['pin_locked_until' => $locked ? null : now()->addYears(10)])->save();
                        AuditLogger::record($locked ? 'pin.unlocked' : 'pin.locked', $r, [(int) $r->id], platform: true, actorRole: 'platform_admin');
                    }),
                Action::make('platformRole')
                    ->label(fn (User $r): string => $r->isPlatformAdmin() ? __('admin.user.remove_admin') : __('admin.user.make_admin'))
                    ->visible(fn (User $r): bool => Gate::allows('changePlatformRole', $r) && $r->email !== null)
                    ->requiresConfirmation()
                    ->color('danger')
                    ->action(function (User $r, Component $livewire): void {
                        Gate::authorize('changePlatformRole', $r);

                        /** @var User $actor */
                        $actor = auth()->user();

                        // Changing someone's platform role: one passkey tap / TOTP code of this session (ADR 0006).
                        if (! app(StepUpService::class)->demandStrong($actor, $livewire)) {
                            return;
                        }
                        $r->forceFill(['platform_role' => $r->isPlatformAdmin() ? null : PlatformRole::PlatformAdmin->value])->save();
                        AuditLogger::record('platform.role_change', $r, [(int) $r->id], data: ['admin' => $r->isPlatformAdmin()], platform: true, actorRole: 'platform_admin');
                    }),
            ]);
    }

    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
        ];
    }
}
