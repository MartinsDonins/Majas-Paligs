<?php

declare(strict_types=1);

namespace App\Livewire\Outage;

use App\Models\Apartment;
use App\Models\Incident;
use App\Models\User;
use App\Services\Outages\IncidentService;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Outage banner (Home + Siltums, `wire:poll.30s`): active incidents covering MY apartment — "Aktīvs atslēgums ·
 * stāvads 5-K · 4 ziņojumi · pirms 12 min" — or one calm line. Users without an apartment (e.g. admin-only) see
 * building-wide notices. Counts only.
 */
final class Banner extends Component
{
    public bool $calmLine = false;

    public function mount(bool $calmLine = false): void
    {
        $this->calmLine = $calmLine;
    }

    public function render(IncidentService $incidents): View
    {
        $building = CurrentBuilding::get();
        /** @var User $user */
        $user = auth()->user();
        $membership = $user->membershipFor($building);
        $apartment = $membership !== null ? Apartment::query()->find($membership->apartment_id) : null;

        $active = $apartment !== null
            ? $incidents->openFor($apartment)
            : Incident::query()->open()->where('scope_key', 'building')->withCount('confirmations')->with(['riser:id,code', 'section:id,number'])->get();

        $last = $active->isEmpty() ? Incident::query()->whereNotNull('resolved_at')->max('resolved_at') : null;

        return view('livewire.outage.banner', [
            'building' => $building,
            'active' => $active->take(3),
            'more' => max(0, $active->count() - 3),
            'lastDays' => $last !== null ? (int) now()->diffInDays($last, true) : null,
            'enabled' => $building->settings->moduleEnabled('outages'),
            'pollSeconds' => (int) config('majas.outages.poll_seconds', 30),
        ]);
    }
}
