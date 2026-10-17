<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use App\Models\User;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Data\MembershipData;
use App\Tenancy\UserScopedQuery;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Building switcher pill "Pūces 51 · 5. k.t." with a sheet listing the user's buildings/apartments
 * (UserScopedQuery DTOs — allow-listed caller).
 */
final class BuildingSwitcher extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();
        $building = CurrentBuilding::find();
        $memberships = UserScopedQuery::for($user)->memberships();
        $current = $building !== null
            ? $memberships->first(static fn (MembershipData $m): bool => $m->buildingId === (int) $building->id)
            : null;

        return view('livewire.shell.building-switcher', [
            'building' => $building,
            'current' => $current,
            'memberships' => $memberships,
        ]);
    }
}
