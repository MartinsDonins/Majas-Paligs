<?php

declare(strict_types=1);

namespace App\Livewire\Parking;

use App\Livewire\Parking\Concerns\ParkingResident;
use App\Models\Vehicle;
use App\Services\Parking\Completeness;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Deklarēšanas kampaņa (PARK-006, MVP-SPEC C.3): per-stairwell progress rings and the building total — AGGREGATES
 * ONLY (counts of covered apartments, never which ones), amnesty window, one call to action.
 */
#[Layout('layouts::app')]
final class CampaignProgress extends Component
{
    use ParkingResident;

    public function render(Completeness $completeness): View
    {
        $building = CurrentBuilding::get();
        $amnesty = $building->settings->parkingAmnestyUntil;
        $amnestyUntil = $amnesty !== null ? CarbonImmutable::parse($amnesty, 'Europe/Riga') : null;
        $apartment = $this->myApartment();

        return view('livewire.parking.campaign-progress', [
            'building' => $building,
            'total' => $completeness->forBuilding(),
            'sections' => $completeness->perSection(),
            'mySection' => $apartment?->section->number,
            'canDeclare' => $apartment !== null && Gate::allows('declare', [Vehicle::class, $apartment]),
            'amnestyUntil' => $amnestyUntil !== null && $amnestyUntil->endOfDay()->isFuture() ? $amnestyUntil : null,
        ])->title(__('parking.progress.title'));
    }
}
