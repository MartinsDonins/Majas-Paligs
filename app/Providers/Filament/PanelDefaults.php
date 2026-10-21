<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\InitialsAvatarProvider;
use App\Http\Middleware\EnsurePinChanged;
use App\Http\Middleware\EnsureSessionEpoch;
use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\SessionLifetime;
use App\Http\Middleware\SetLocale;
use Filament\Actions\Action;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Shared configuration of both Filament panels: our session/CSRF stack (no XSRF-TOKEN cookie), LV/RU locale (with
 * an LV/RU switch in the top bar), petrol palette and Inter from the shared theme (tokens.css), no global search, no
 * Filament login (users sign in through the resident app: magic link / PIN; panels then require step-up), local
 * initials avatars and a relative favicon (CSP: img-src 'self' data: blob:).
 */
final class PanelDefaults
{
    public static function apply(Panel $panel): Panel
    {
        return $panel
            ->brandName('Mājas Palīgs')
            // Relative: asset() runs at panel registration, before the forwarded https scheme is known, and produced
            // an http:// URL that CSP blocked on every page.
            ->favicon('/icons/icon.svg')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, static fn (): View => view('filament.language-switch'))
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, static fn (): View => view('filament.session-expired'))
            // Passkeys, trusted devices and the authenticator app live in the resident shell (/me/security).
            ->userMenuItems([
                Action::make('security')
                    ->label(static fn (): string => __('me.security.link'))
                    ->icon(Heroicon::OutlinedFingerPrint)
                    ->url(static fn (): string => route('me.security')),
            ])
            ->colors([
                'primary' => Color::generatePalette('#0B4F55'),
                'gray' => Color::Stone,
                'danger' => Color::generatePalette('#B42318'),
                'warning' => Color::generatePalette('#A15C00'),
                'success' => Color::generatePalette('#1E7A46'),
                'info' => Color::generatePalette('#13808A'),
            ])
            ->font('Inter Variable', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/theme.css')
            ->globalSearch(false)
            ->maxContentWidth(Width::SevenExtraLarge)
            ->sidebarCollapsibleOnDesktop()
            ->unsavedChangesAlerts()
            ->homeUrl(fn () => route('home.redirect'))
            ->middleware([
                EncryptCookies::class,
                SessionLifetime::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                EnsureSessionEpoch::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocale::class,
                EnsurePinChanged::class,
            ]);
    }
}
