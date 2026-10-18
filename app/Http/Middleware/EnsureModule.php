<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\CurrentBuilding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:heating,outages` — at least one of the listed modules must be enabled in the current building
 * (buildings.settings.modules). Runs after `member`; a disabled module answers 404 like a missing page.
 */
final class EnsureModule
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $settings = CurrentBuilding::get()->settings;

        foreach ($modules as $module) {
            if ($settings->moduleEnabled($module)) {
                return $next($request);
            }
        }

        abort(404);
    }
}
