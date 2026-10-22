<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Models\ApartmentMembership;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `member`: the user must hold an approved or pending membership, or an active building role, in the current
 * building. Otherwise 404 — identical to an unknown slug, so building slugs cannot be enumerated (PLANNING §4.2).
 * Runs right after SetCurrentBuilding; only the user's own membership/role rows are read before the decision.
 */
final class EnsureMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isTombstone()) {
            abort(404);
        }

        $building = CurrentBuilding::get();
        $membership = $user->membershipFor($building);
        $roles = $membership === null || ! $membership->isApproved() ? $user->rolesIn($building) : null;

        if ($membership === null && ($roles === null || $roles->isEmpty())) {
            // A former member (rejected / ended) already knows this building exists: send them to /home, which
            // explains what happened, instead of "Lapa nav atrasta". Everyone else still gets the anti-enumeration 404.
            if (ApartmentMembership::query()->where('user_id', $user->id)
                ->whereIn('status', [MembershipStatus::Rejected->value, MembershipStatus::Ended->value])->exists()) {
                return redirect()->route('home.redirect');
            }

            // FIRSTRUN-001: a platform_admin may know every house (no enumeration risk): explain why the resident view
            // of this house is empty for him and offer the next step instead of "Lapa nav atrasta".
            if ($user->isPlatformAdmin() && ! $request->expectsJson()) {
                return redirect()->route('admin.house', ['building' => $building->slug])->with('status', 'no_home_here');
            }

            abort(404);
        }

        $request->attributes->set('mp.membership', $membership);

        return $next($request);
    }
}
