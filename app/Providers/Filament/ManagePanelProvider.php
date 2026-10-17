<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\RequireStepUp;
use App\Http\Middleware\SetCurrentBuilding;
use App\Models\Building;
use Filament\Http\Middleware\Authenticate;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * `manage` panel (/manage/{building}, MVP-SPEC A.8) with Filament native tenancy + our scope: Filament resolves
 * the tenant through User::getTenants()/canAccessTenant() (UserScopedQuery, no tenant query), then the
 * persistent tenant middleware SetCurrentBuilding sets the context. Requires a building role + step-up.
 */
final class ManagePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return PanelDefaults::apply($panel)
            ->id('manage')
            ->path('manage')
            ->default()
            ->tenant(Building::class, slugAttribute: 'slug', ownershipRelationship: 'building')
            ->tenantMiddleware([SetCurrentBuilding::class], isPersistent: true)
            ->discoverResources(in: app_path('Filament/Manage/Resources'), for: 'App\\Filament\\Manage\\Resources')
            ->discoverPages(in: app_path('Filament/Manage/Pages'), for: 'App\\Filament\\Manage\\Pages')
            ->discoverWidgets(in: app_path('Filament/Manage/Widgets'), for: 'App\\Filament\\Manage\\Widgets')
            ->pages([Dashboard::class])
            ->authMiddleware([
                Authenticate::class,
                RequireStepUp::class,
            ]);
    }
}
