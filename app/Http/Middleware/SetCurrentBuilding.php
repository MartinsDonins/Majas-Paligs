<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Building;
use App\Tenancy\CurrentBuilding;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant context (PLANNING §4.2 allow-listed caller #1):
 * - resident routes: from the route-bound `{building:slug}` (Building is not a tenant model);
 * - Filament `manage` panel: from Filament::getTenant(), which Filament resolved through
 *   User::getTenants()/canAccessTenant() BEFORE this middleware runs.
 * Registered as persistent Livewire middleware so /livewire/update re-runs it.
 */
final class SetCurrentBuilding
{
    public function handle(Request $request, Closure $next): Response
    {
        $building = $request->route('building');

        if (! $building instanceof Building) {
            $tenant = Filament::getTenant();
            $building = $tenant instanceof Building ? $tenant : null;
        }

        if ($building === null) {
            abort(404);
        }

        CurrentBuilding::set($building);

        return $next($request);
    }
}
