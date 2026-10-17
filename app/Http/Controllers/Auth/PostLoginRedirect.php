<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\MembershipStatus;
use App\Models\User;
use App\Tenancy\Data\MembershipData;
use App\Tenancy\UserScopedQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /home: sends the user to their building (approved membership first, then pending, then a role), the
 * platform panel for platform admins, or /join when nothing is linked yet. Allow-listed UserScopedQuery caller.
 */
final class PostLoginRedirect
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $scoped = UserScopedQuery::for($user);

        $memberships = $scoped->memberships();
        $remembered = (string) $request->session()->get('mp.last_building', '');

        /** @var MembershipData|null $pick */
        $pick = $memberships->first(static fn (MembershipData $m): bool => $m->buildingSlug === $remembered)
            ?? $memberships->first(static fn (MembershipData $m): bool => $m->status === MembershipStatus::Approved)
            ?? $memberships->first();

        if ($pick !== null) {
            return redirect()->route('building.home', ['building' => $pick->buildingSlug]);
        }

        $role = $scoped->roles()->first();
        if ($role !== null) {
            return redirect()->route('building.home', ['building' => $role->buildingSlug]);
        }

        if ($user->isPlatformAdmin()) {
            return redirect('/platform');
        }

        return redirect()->route('join')->with('status', 'no_apartment');
    }
}
