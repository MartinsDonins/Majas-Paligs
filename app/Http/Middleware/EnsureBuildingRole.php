<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\BuildingRoleType;
use App\Models\User;
use App\Tenancy\CurrentBuilding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `role:section_elder,building_admin` — any of the listed active roles in the current building. Runs after
 * `member`; non-holders get 403 (they are members, so the building's existence is not a secret to them).
 */
final class EnsureBuildingRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(404);
        }

        $wanted = array_map(static fn (string $r): BuildingRoleType => BuildingRoleType::from($r), $roles);
        $has = $user->rolesIn(CurrentBuilding::get())->contains(static fn ($r): bool => in_array($r->role, $wanted, true));

        abort_unless($has, 403);

        return $next($request);
    }
}
