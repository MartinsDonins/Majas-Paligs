<?php

declare(strict_types=1);

namespace App\Livewire\Home;

use App\Enums\BuildingRoleType;
use App\Gdpr\CoreGate;
use App\Gdpr\ParkingGate;
use App\Http\Middleware\EnsureModule;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\User;
use App\Services\Buildings\BuildingGrid;
use App\Services\Heating\HeatingGridQuery;
use App\Services\Opening\OpeningChecklist;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Home (Sākums, DESIGN-SYSTEM §6.2): calm status line, module-dependent big actions (B/C tiles appear when the
 * modules are enabled), my stairwell mini map (structure + my apartment only), "Mana māja" card, the water
 * metering deadline card and, for elders/admins, a shortcut to their tools. Pending members see read-only.
 */
#[Layout('layouts::app')]
final class Dashboard extends Component
{
    public function render(BuildingGrid $grids, HeatingGridQuery $heatingQuery): View
    {
        $building = CurrentBuilding::get();
        /** @var User $user */
        $user = auth()->user();
        $membership = $user->membershipFor($building);
        $apartment = $membership !== null
            ? Apartment::query()->with('section')->whereKey($membership->apartment_id)->first()
            : null;

        $roles = $user->rolesIn($building);
        $isElder = $roles->contains(fn ($r) => $r->role === BuildingRoleType::SectionElder);
        $isAdmin = $roles->contains(fn ($r) => $r->role === BuildingRoleType::BuildingAdmin);

        $pendingForMe = 0;
        if ($isAdmin || $isElder) {
            $sectionIds = $user->elderSectionIds($building);
            $pendingForMe = ApartmentMembership::query()->pending()->where('user_id', '!=', $user->id)
                ->when(! $isAdmin, fn ($q) => $q->whereIn('apartment_id', Apartment::query()->whereIn('section_id', $sectionIds)->select('id')))
                ->count();
        }

        $section = $apartment !== null ? ($grids->forCurrentBuilding([$apartment->section->number])['sections'][0] ?? null) : null;
        $heatingOn = $building->settings->moduleEnabled('heating');
        // Mini map of my stairwell with the column aggregates (resident read model — counts only).
        $heatingView = $apartment !== null && $heatingOn ? $heatingQuery->residentSection($apartment->section) : null;
        $deadline = CarbonImmutable::parse('2027-01-01', 'Europe/Riga');

        session()->put('mp.last_building', $building->slug);

        // FIRSTRUN-001: never an empty home. A house that is not open yet says so (with the way on for its admin),
        // and every module has a card — open, or locked with the reason and who opens it.
        $houseOpen = CoreGate::status($building)->open;
        $parkingGate = ParkingGate::status($building);
        $tiles = self::moduleTiles($building->settings->moduleEnabled('heating'), $building->settings->moduleEnabled('outages'), $parkingGate->residentEnabled(), $parkingGate->l2(), $houseOpen);

        return view('livewire.home.dashboard', [
            'houseOpen' => $houseOpen,
            'coreProgress' => $houseOpen ? null : OpeningChecklist::for($building)->progress('core'),
            'tiles' => $tiles,
            'fixUrls' => $isAdmin ? array_map(static fn (string $reason): string => EnsureModule::fixUrl($building->slug, $reason), array_filter(array_column($tiles, 'reason', 'key'))) : [],
            'wizardUrl' => url('/admin/b/'.$building->slug.'/opening'),
            'canLinkSelf' => $membership === null && ($isAdmin || $user->isPlatformAdmin()),
            'building' => $building,
            'membership' => $membership,
            'apartment' => $apartment,
            'section' => $section,
            'isElder' => $isElder,
            'isAdmin' => $isAdmin,
            'pendingForMe' => $pendingForMe,
            'heating' => $heatingOn,
            'heatingView' => $heatingView,
            'outages' => $building->settings->moduleEnabled('outages'),
            // Parking tiles only with modules.parking ON and gate L2 (MVP-SPEC C.0, A.7).
            'parking' => ParkingGate::residentEnabled($building),
            'daysToDeadline' => max(0, (int) now('Europe/Riga')->startOfDay()->diffInDays($deadline, false)),
            'largeText' => $user->text_size->value === 'large',
        ])->title(__('nav.home'));
    }

    /**
     * One card per resident module: `reason` null = open; otherwise why it is closed (lang `start.locked.<reason>`,
     * EnsureModule uses the same reasons for the module page itself).
     *
     * @return list<array{key: string, reason: ?string}>
     */
    public static function moduleTiles(bool $heating, bool $outages, bool $parkingOn, bool $parkingL2, bool $houseOpen): array
    {
        $closed = static fn (bool $on, string $offReason): ?string => match (true) {
            ! $houseOpen => 'house_closed',
            ! $on => $offReason,
            default => null,
        };

        return [
            ['key' => 'heating', 'reason' => $closed($heating, 'module_off')],
            ['key' => 'outages', 'reason' => $closed($outages, 'module_off')],
            ['key' => 'parking', 'reason' => $closed($parkingOn, $parkingL2 ? 'parking_off' : 'parking_gate')],
        ];
    }
}
