<?php

declare(strict_types=1);

namespace App\Livewire\Parking;

use App\Livewire\Parking\Concerns\ParkingResident;
use App\Models\Vehicle;
use App\Services\Parking\DeclarationResult;
use App\Services\Parking\VehicleRegistry;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Deklarēšana (PARK-006, MVP-SPEC C.3): "Mums ir auto" → plate (+ country, company car) → always the identical
 * "Paldies — gaida stāvvietas atbildīgā apstiprinājumu" (no registration oracle); or "Mums nav auto". Shows the
 * amnesty notice while `parking_amnesty_until` is in the future and the privacy box before saving.
 */
#[Layout('layouts::app')]
final class DeclarationWizard extends Component
{
    use ParkingResident;

    #[Locked]
    public string $step = 'choose';

    public string $country = 'LV';

    public bool $company = false;

    #[Locked]
    public ?int $retryAfter = null;

    #[Locked]
    public ?string $error = null;

    public function choose(string $choice): void
    {
        $this->error = null;
        $this->step = $choice === 'car' ? 'car' : 'choose';
    }

    public function declareCar(string $plate = ''): void
    {
        $apartment = $this->myApartment();
        abort_if($apartment === null, 403);
        Gate::authorize('declare', [Vehicle::class, $apartment]);

        $this->error = null;
        $result = app(VehicleRegistry::class)->declare($this->user(), $apartment, $plate, $this->country, $this->company);

        if ($result->status === DeclarationResult::INVALID) {
            $this->error = 'invalid';

            return;
        }

        if ($result->status === DeclarationResult::RATE_LIMITED) {
            $this->retryAfter = $result->retryAfter;

            return;
        }

        $this->retryAfter = null;
        $this->company = false;
        $this->step = 'done';
    }

    public function declareNoCar(): void
    {
        $apartment = $this->myApartment();
        abort_if($apartment === null, 403);
        Gate::authorize('declare', [Vehicle::class, $apartment]);

        if (! app(VehicleRegistry::class)->declareNoVehicle($this->user(), $apartment)) {
            $this->error = 'has_vehicles';

            return;
        }

        $this->step = 'no_car_done';
    }

    public function another(): void
    {
        $this->step = 'car';
        $this->error = null;
    }

    public function render(): View
    {
        $building = CurrentBuilding::get();
        $amnesty = $building->settings->parkingAmnestyUntil;
        $amnestyUntil = $amnesty !== null ? CarbonImmutable::parse($amnesty, 'Europe/Riga') : null;
        $apartment = $this->myApartment();

        return view('livewire.parking.declaration-wizard', [
            'building' => $building,
            'apartment' => $apartment,
            'canDeclare' => $apartment !== null && Gate::allows('declare', [Vehicle::class, $apartment]),
            'amnestyUntil' => $amnestyUntil !== null && $amnestyUntil->endOfDay()->isFuture() ? $amnestyUntil : null,
        ])->title(__('parking.declare.title'));
    }
}
