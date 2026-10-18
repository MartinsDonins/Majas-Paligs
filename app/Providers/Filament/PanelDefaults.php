<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\EnsurePinChanged;
use App\Http\Middleware\EnsureSessionEpoch;
use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\SetLocale;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Shared configuration of both Filament panels: our session/CSRF stack (no XSRF-TOKEN cookie), LV/RU locale,
 * petrol palette and Inter from the shared theme (tokens.css), no global search, no Filament login (users sign
 * in through the resident app: magic link / PIN; panels then require step-up).
 */
final class PanelDefaults
{
    public static function apply(Panel $panel): Panel
    {
        return $panel
            ->brandName('Mājas Palīgs')
            ->favicon(asset('icons/icon.svg'))
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
