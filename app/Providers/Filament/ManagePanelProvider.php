<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\AdminPanelEntry;
use App\Http\Middleware\RequireStepUp;
use App\Http\Middleware\SetCurrentBuilding;
use App\Models\Building;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;

/**
 * `manage` panel — per-building administration at /admin/b/{building} (ROUND2-SPEC §1, D3; panel id, route names
 * and policies unchanged from /manage) with Filament native tenancy + our scope: Filament resolves the tenant through
 * User::getTenants()/canAccessTenant() (UserScopedQuery, no tenant query), then the persistent tenant middleware
 * SetCurrentBuilding sets the context. Requires a building role + step-up.
 *
 * Navigation groups (§1.2): Cilvēki, Māja, Siltums un ūdens, Stāvvieta, Pārvaldība — labels are closures so the
 * group order also holds in Russian. The tenant menu adds "Visas mājas" (/admin) and, for a platform_admin, "Platforma".
 */
final class ManagePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return PanelDefaults::apply($panel)
            ->id('manage')
            ->path('admin/b')
            ->default()
            ->tenant(Building::class, slugAttribute: 'slug', ownershipRelationship: 'building')
            ->tenantMiddleware([SetCurrentBuilding::class], isPersistent: true)
            ->tenantMenuItems([
                Action::make('allBuildings')
                    ->label(static fn (): string => __('admin.entry.all_buildings'))
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->url(static fn (): string => route('admin.entry')),
                Action::make('platform')
                    ->label(static fn (): string => __('admin.entry.platform'))
                    ->icon(Heroicon::OutlinedServerStack)
                    ->url('/admin/platform')
                    ->visible(static fn (): bool => auth()->user() instanceof User && auth()->user()->isPlatformAdmin()),
            ])
            ->navigationGroups([
                NavigationGroup::make('people')->label(static fn (): string => __('admin.nav.people')),
                NavigationGroup::make('structure')->label(static fn (): string => __('admin.nav.structure')),
                NavigationGroup::make('heating')->label(static fn (): string => __('admin.nav.heating')),
                NavigationGroup::make('parking')->label(static fn (): string => __('admin.nav.parking')),
                NavigationGroup::make('governance')->label(static fn (): string => __('admin.nav.governance')),
            ])
            ->discoverResources(in: app_path('Filament/Manage/Resources'), for: 'App\\Filament\\Manage\\Resources')
            ->discoverPages(in: app_path('Filament/Manage/Pages'), for: 'App\\Filament\\Manage\\Pages')
            ->discoverWidgets(in: app_path('Filament/Manage/Widgets'), for: 'App\\Filament\\Manage\\Widgets')
            ->pages([Dashboard::class])
            ->authMiddleware([
                AdminPanelEntry::class,
                RequireStepUp::class,
            ]);
    }
}
