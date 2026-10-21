<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\RequireStepUp;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;

/**
 * `platform` panel at /admin/platform (ROUND2-SPEC §1, D3; was /platform): series catalogue, buildings, generator,
 * users, legal and privacy notices, PIN pause, platform audit + break-glass. platform_admin only
 * (User::canAccessPanel) with a fresh MFA step-up. NO tenant middleware: tenant reads/writes go through
 * PlatformBuildingContext or BreakGlass. The user menu links back to the /admin chooser ("Mājas administrēšana").
 */
final class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return PanelDefaults::apply($panel)
            ->id('platform')
            ->path('admin/platform')
            ->userMenuItems([
                Action::make('houseAdmin')
                    ->label(static fn (): string => __('admin.entry.house_admin'))
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->url(static fn (): string => route('admin.entry')),
            ])
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\Filament\Platform\Resources')
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\Filament\Platform\Pages')
            ->pages([Dashboard::class])
            ->authMiddleware([
                Authenticate::class,
                RequireStepUp::class.':mfa',
            ]);
    }
}
