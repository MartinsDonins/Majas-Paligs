<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Building;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * "Admin skats ⇄ Iedzīvotāja skats" (FIRSTRUN-001): one toggle in the top bar of the resident app and of both
 * Filament panels, shown only to someone who can administer something (a manage role in any house, or
 * platform_admin). The toggle goes through GET /view/{mode}?b={slug} (ViewSwitchController), which remembers the
 * choice in the `mp_view` cookie and deep-links to the same house:
 * - admin:    /admin/b/{slug} when the person administers that house; the platform house page for a platform_admin
 *             without a role there; otherwise the /admin chooser;
 * - resident: /b/{slug} when the person holds a role there (EnsureMember lets role holders in); otherwise /home.
 * `/home` follows a remembered admin view (landing "Turpināt", after login), so the app opens where the person left.
 * The cookie only picks between pages the person may open anyway — it grants nothing.
 */
final class ViewSwitch
{
    public const COOKIE = 'mp_view';

    public const ADMIN = 'admin';

    public const RESIDENT = 'resident';

    public static function canSwitch(?User $user): bool
    {
        if (! $user instanceof User || $user->isTombstone()) {
            return false;
        }

        return $user->isPlatformAdmin() || $user->canAccessPanel(Filament::getPanel('manage'));
    }

    public static function url(string $mode, ?Building $building = null): string
    {
        return route('view.switch', array_filter(['mode' => $mode, 'b' => $building?->slug]));
    }

    /**
     * Where the admin view of $building is for $user.
     */
    public static function adminTarget(User $user, ?Building $building): string
    {
        if ($building !== null && $user->canAccessTenant($building)) {
            return '/admin/b/'.$building->slug;
        }

        if ($building !== null && $user->isPlatformAdmin()) {
            return route('admin.house', ['building' => $building->slug], absolute: false);
        }

        return route('admin.entry', absolute: false);
    }

    /**
     * Where the resident view of $building is for $user.
     */
    public static function residentTarget(User $user, ?Building $building): string
    {
        if ($building !== null && $user->canAccessTenant($building)) {
            return route('building.home', ['building' => $building->slug], absolute: false);
        }

        return route('home.redirect', absolute: false);
    }

    public static function remembered(Request $request): ?string
    {
        $value = $request->cookie(self::COOKIE);

        return in_array($value, [self::ADMIN, self::RESIDENT], true) ? $value : null;
    }
}
