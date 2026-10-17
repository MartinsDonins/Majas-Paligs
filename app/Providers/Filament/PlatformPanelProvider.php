<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\RequireStepUp;
use Filament\Http\Middleware\Authenticate;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * `platform` panel (/platform, MVP-SPEC A.8): series catalogue, buildings, generator, users, legal and privacy
 * notices, PIN pause, platform audit + break-glass. platform_admin only (User::canAccessPanel) with a fresh
 * TOTP step-up. NO tenant middleware: tenant reads/writes go through PlatformBuildingContext or BreakGlass.
 */
final class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return PanelDefaults::apply($panel)
            ->id('platform')
            ->path('platform')
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\\Filament\\Platform\\Resources')
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\\Filament\\Platform\\Pages')
            ->pages([Dashboard::class])
            ->authMiddleware([
                Authenticate::class,
                RequireStepUp::class.':mfa',
            ]);
    }
}
