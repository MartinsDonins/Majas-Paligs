<?php

declare(strict_types=1);

namespace App\Livewire\Parking;

use App\Enums\PermitType;
use App\Livewire\Parking\Concerns\ParkingResident;
use App\Models\Permit;
use App\Models\Vehicle;
use App\Services\Parking\DeclarationResult;
use App\Services\Parking\VehicleRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Mainīt auto" (PARK-006): new plate → "nodošu veco caurlaidi" → pending for the parking officer, who verifies the
 * new car; the main permit is then REPLACED (old returned, history kept). Same neutral answer as any declaration.
 */
final class ChangeVehicleWizard extends Component
{
    use ParkingResident;

    #[Locked]
    public int $permitId;

    public bool $returnOld = false;

    public string $country = 'LV';

    #[Locked]
    public ?string $state = null;

    #[Locked]
    public ?int $retryAfter = null;

    public function mount(int $permitId): void
    {
        $this->permitId = $permitId;
    }

    public function submit(string $plate = ''): void
    {
        $apartment = $this->myApartment();
        abort_if($apartment === null, 403);
        Gate::authorize('declare', [Vehicle::class, $apartment]);

        $permit = Permit::query()->active()->where('apartment_id', $apartment->id)
            ->where('type', PermitType::Main->value)->findOrFail($this->permitId);

        if (! $this->returnOld) {
            $this->state = 'confirm_return';

            return;
        }

        $result = app(VehicleRegistry::class)->declare($this->user(), $apartment, $plate, $this->country, replacing: $permit);
        $this->state = $result->status;
        $this->retryAfter = $result->status === DeclarationResult::RATE_LIMITED ? $result->retryAfter : null;
    }

    public function render(): View
    {
        $apartment = $this->myApartment();
        $permit = $apartment !== null
            ? Permit::query()->active()->where('apartment_id', $apartment->id)->find($this->permitId)
            : null;

        return view('livewire.parking.change-vehicle-wizard', ['permit' => $permit]);
    }
}
