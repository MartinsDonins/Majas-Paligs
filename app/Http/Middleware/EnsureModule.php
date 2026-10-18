<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Gdpr\ParkingGate;
use App\Tenancy\CurrentBuilding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:heating,outages` — at least one of the listed modules must be enabled in the current building
 * (buildings.settings.modules). Runs after `member`; a disabled module answers 404 like a missing page.
 * `parking` additionally requires parking gate L2 (MVP-SPEC C.0 enforcement 3) — below L2 every resident parking
 * route 404s even if the flag were left on.
 */
final class EnsureModule
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $building = CurrentBuilding::get();
        $settings = $building->settings;

        foreach ($modules as $module) {
            if (! $settings->moduleEnabled($module)) {
                continue;
            }

            if ($module === 'parking' && ! ParkingGate::status($building)->residentEnabled()) {
                continue;
            }

            return $next($request);
        }

        abort(404);
    }
}
