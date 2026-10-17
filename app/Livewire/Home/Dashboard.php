<?php

declare(strict_types=1);

namespace App\Livewire\Home;

use App\Enums\BuildingRoleType;
use App\Models\Apartment;
use App\Models\ApartmentMembership;
use App\Models\User;
use App\Services\Buildings\BuildingGrid;
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
    public function render(BuildingGrid $grids): View
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
            $pendingForMe = ApartmentMembership::query()->pending()
                ->when(! $isAdmin, fn ($q) => $q->whereIn('apartment_id', Apartment::query()->whereIn('section_id', $sectionIds)->select('id')))
                ->count();
        }

        $section = $apartment !== null ? ($grids->forCurrentBuilding([$apartment->section->number])['sections'][0] ?? null) : null;
        $deadline = CarbonImmutable::parse('2027-01-01', 'Europe/Riga');

        session()->put('mp.last_building', $building->slug);

        return view('livewire.home.dashboard', [
            'building' => $building,
            'membership' => $membership,
            'apartment' => $apartment,
            'section' => $section,
            'isElder' => $isElder,
            'isAdmin' => $isAdmin,
            'pendingForMe' => $pendingForMe,
            'heating' => $building->settings->moduleEnabled('heating'),
            'outages' => $building->settings->moduleEnabled('outages'),
            'parking' => $building->settings->moduleEnabled('parking'),
            'daysToDeadline' => max(0, (int) now('Europe/Riga')->startOfDay()->diffInDays($deadline, false)),
            'largeText' => $user->text_size->value === 'large',
        ])->title(__('nav.home'));
    }
}
