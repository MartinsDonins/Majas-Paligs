<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
            abort(404);
        }

        $request->attributes->set('mp.membership', $membership);

        return $next($request);
    }
}
