<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\User;
use App\Support\ViewSwitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /view/{admin|resident}?b={slug} — the "Admin skats / Iedzīvotāja skats" toggle (FIRSTRUN-001, ViewSwitch).
 * Remembers the choice for a year in `mp_view` and sends the person to the same house in the other view. The slug
 * only selects a target the person may open anyway (canAccessTenant / platform_admin); an unknown slug falls back to
 * the generic entry, so the toggle cannot be used to probe for houses.
 */
final class ViewSwitchController
{
    public function __invoke(Request $request, string $mode): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $slug = (string) $request->query('b', '');
        $building = preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1
            ? Building::query()->where('slug', $slug)->first()
            : null;

        if ($mode === ViewSwitch::ADMIN && ! ViewSwitch::canSwitch($user)) {
            return redirect()->route('home.redirect');
        }

        $target = $mode === ViewSwitch::ADMIN
            ? ViewSwitch::adminTarget($user, $building)
            : ViewSwitch::residentTarget($user, $building);

        return redirect($target)->withCookie(cookie(ViewSwitch::COOKIE, $mode, 60 * 24 * 365, httpOnly: true, sameSite: 'lax'));
    }
}
