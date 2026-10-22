<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BuildingRoleType;
use App\Gdpr\CoreGate;
use App\Models\Building;
use App\Models\User;
use App\Platform\PlatformHouses;
use App\Tenancy\Data\RoleData;
use App\Tenancy\UserScopedQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /admin — the one entry to administration (ROUND2-SPEC §1.1, ADMIN-005). Targets are the buildings where the
 * user holds a `manage` role (building_admin, section_elder, parking_officer) plus the platform panel for a
 * platform_admin. None → /home with a message; exactly one (and not a platform_admin) → straight there; otherwise a
 * chooser card per target (name, address, the user's roles, L-core summary). A platform_admin additionally sees every
 * house without a role of his, linked to the platform house page (FIRSTRUN-001, PlatformHouses via BuildingIterator). Allow-listed UserScopedQuery caller: the gate summary needs each
 * building's context, which only UserScopedQuery::eachBuilding (a sanctioned context setter) provides.
 */
final class AdminEntryController
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $scoped = UserScopedQuery::for($user);

        /** @var array<int, list<RoleData>> $rolesByBuilding */
        $rolesByBuilding = $scoped->roles()
            ->filter(static fn (RoleData $r): bool => $r->isManageRole())
            ->groupBy(static fn (RoleData $r): int => $r->buildingId)
            ->map(static fn ($roles): array => $roles->values()->all())
            ->all();

        $platform = $user->isPlatformAdmin();
        $targets = count($rolesByBuilding) + ($platform ? 1 : 0);

        if ($targets === 0) {
            return redirect()->route('home.redirect')->with('status', 'no_admin_role');
        }

        // FIRSTRUN-001: a platform_admin always gets the chooser — with exactly one target it used to jump to the empty
        // platform dashboard, and every way out of it led back there.
        if ($targets === 1 && ! $platform) {
            /** @var RoleData $first */
            $first = array_values($rolesByBuilding)[0][0];

            return redirect('/admin/b/'.$first->buildingSlug);
        }

        $cards = [];
        $scoped->eachBuilding(static function (Building $building) use (&$cards, $rolesByBuilding): void {
            $roles = $rolesByBuilding[(int) $building->id] ?? null;
            if ($roles === null) {
                return;
            }

            $cards[] = [
                'slug' => $building->slug,
                'name' => $building->name,
                'address' => trim($building->address_line.', '.$building->city, ', '),
                'roles' => self::roleLabels($roles),
                'core_open' => CoreGate::status($building)->open,
            ];
        });

        usort($cards, static fn (array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));

        // A platform_admin also sees every house where he holds no administration role yet ("Jūsu loma: nav"), with
        // "Kļūt par šīs mājas administratoru" on the platform house page. Still no house in the Filament switcher
        // without a role (ROUND2-SPEC §1.3) — the panel opens only after the role exists.
        $unclaimed = $platform
            ? array_values(array_filter(PlatformHouses::overview($user), static fn (array $row): bool => ! isset($rolesByBuilding[$row['id']])))
            : [];

        return view('admin.entry', ['cards' => $cards, 'platform' => $platform, 'unclaimed' => $unclaimed]);
    }

    /**
     * One label per role kind the user holds in the building (enum order, no duplicates).
     *
     * @param  list<RoleData>  $roles
     * @return list<string>
     */
    private static function roleLabels(array $roles): array
    {
        $labels = [];
        foreach (BuildingRoleType::cases() as $type) {
            $mine = array_values(array_filter($roles, static fn (RoleData $r): bool => $r->role === $type));
            if ($mine === []) {
                continue;
            }
            $labels[] = $type->label();
        }

        return $labels;
    }
}
