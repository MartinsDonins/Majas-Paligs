<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Gdpr\CoreGate;
use App\Gdpr\ParkingGate;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:heating,outages` — at least one of the listed modules must be enabled in the current building
 * (buildings.settings.modules). Runs after `member`; a disabled module answers 404 like a missing page.
 * `parking` additionally requires parking gate L2 (MVP-SPEC C.0 enforcement 3) — below L2 every resident parking
 * route 404s even if the flag were left on.
 *
 * FIRSTRUN-001: the 404 keeps its status, but a MEMBER of this house (the only person who gets this far) sees why the
 * page is closed and who can open it — "Māja vēl nav atvērta", "Izslēgts — ieslēdz mājas administrators" or
 * "Stāvvieta iedzīvotājiem vēl nav atvērta" — with a button to the fix for the house's building_admin. Nothing about
 * the module's data is shown.
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

        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            abort(404);
        }

        $user = $request->user();
        $module = $modules[0] ?? 'heating';
        $houseOpen = CoreGate::status($building)->open;
        $reason = match (true) {
            ! $houseOpen => 'house_closed',
            $module === 'parking' && ! ParkingGate::status($building)->l2() => 'parking_gate',
            $module === 'parking' => 'parking_off',
            default => 'module_off',
        };
        $isAdmin = $user instanceof User && $user->isBuildingAdmin($building);

        return response()->view('pages.module-locked', [
            'building' => $building,
            'module' => $module,
            'reason' => $reason,
            'fixUrl' => $isAdmin ? self::fixUrl($building->slug, $reason) : null,
        ], 404);
    }

    /**
     * The admin page that removes the reason (opening wizard, parking status, settings → modules).
     */
    public static function fixUrl(string $slug, string $reason): string
    {
        return match ($reason) {
            'house_closed' => url('/admin/b/'.$slug.'/opening'),
            'parking_gate' => url('/admin/b/'.$slug.'/parking-status'),
            'parking_off' => url('/admin/b/'.$slug.'/opening').'#step-parking_on',
            default => url('/admin/b/'.$slug.'/settings'),
        };
    }
}
