<?php

declare(strict_types=1);

namespace App\Livewire\Parking;

use App\Enums\PermitStatusReason;
use App\Enums\PermitType;
use App\Enums\VehicleStatus;
use App\Livewire\Parking\Concerns\ParkingResident;
use App\Models\Permit;
use App\Models\Vehicle;
use App\Services\Parking\PlateNormalizer;
use App\Services\Parking\VehicleRegistry;
use App\Services\Parking\VehicleRemovalService;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Manas caurlaides (DESIGN-SYSTEM §6.7, PARK-006): header "Pamata N · Viesu M", permit cards (type chip, number,
 * plate badge of the bound OWN vehicle, valid-to + "Beidzas drīz"), the apartment's vehicles (own plates only —
 * decrypted on this policy-gated path), "Mainīt auto" (ChangeVehicleWizard), "Pievienot auto", the yearly
 * "Vai šis auto vēl aktuāls?" and the 2-step "Dzēst manu numuru" that warns when a main permit is bound.
 */
#[Layout('layouts::app')]
final class MyPermits extends Component
{
    use ParkingResident;

    /** Vehicle id awaiting the 2nd delete confirmation (set server-side only). */
    #[Locked]
    public ?string $confirmRemove = null;

    #[Locked]
    public ?string $flash = null;

    public function askRemove(string $vehicleId): void
    {
        $vehicle = $this->ownVehicle($vehicleId);
        Gate::authorize('delete', $vehicle);
        $this->confirmRemove = $vehicle->id;
    }

    public function cancelRemove(): void
    {
        $this->confirmRemove = null;
    }

    public function remove(VehicleRemovalService $removal): void
    {
        abort_if($this->confirmRemove === null, 403);
        $vehicle = $this->ownVehicle($this->confirmRemove);
        Gate::authorize('delete', $vehicle);

        $removal->remove((string) $vehicle->id, PermitStatusReason::VehicleRemoved, actorUserId: (int) $this->user()->id, actorRole: 'self');
        $this->confirmRemove = null;
        $this->flash = 'removed';
    }

    public function reconfirm(string $vehicleId, VehicleRegistry $registry): void
    {
        $vehicle = $this->ownVehicle($vehicleId);
        Gate::authorize('update', $vehicle);
        $registry->reconfirm($this->user(), $vehicle);
        $this->flash = 'reconfirmed';
    }

    public function render(VehicleRegistry $registry): View
    {
        $apartment = $this->myApartment();
        $vehicles = collect();
        $permits = collect();

        if ($apartment !== null && Gate::allows('viewOwn', [Vehicle::class, $apartment])) {
            $vehicles = Vehicle::query()->current()->where('apartment_id', $apartment->id)->orderBy('created_at')->get()
                ->filter(fn (Vehicle $v): bool => Gate::allows('view', $v))
                ->map(fn (Vehicle $v): array => [
                    'id' => $v->id,
                    'plate' => $registry->plateOf($v),
                    'country' => $v->country,
                    'company' => $v->is_company_car,
                    'pending' => $v->status === VehicleStatus::Pending,
                    'reconfirm' => $v->status === VehicleStatus::Active && $v->reconfirm_due_at !== null && $v->reconfirm_due_at->isPast(),
                ])->keyBy('id');

            $permits = Permit::query()->where('apartment_id', $apartment->id)->active()
                ->orderBy('type')->orderBy('number')->get();
        }

        $boundPermit = $this->confirmRemove !== null
            ? $permits->first(fn (Permit $p): bool => $p->type === PermitType::Main && $p->vehicle_id === $this->confirmRemove)
            : null;

        return view('livewire.parking.my-permits', [
            'building' => CurrentBuilding::get(),
            'apartment' => $apartment,
            'vehicles' => $vehicles,
            'permits' => $permits,
            'mainCount' => $permits->where('type', PermitType::Main)->count(),
            'guestCount' => $permits->where('type', PermitType::Guest)->count(),
            'boundPermit' => $boundPermit,
            'canDeclare' => $apartment !== null && Gate::allows('declare', [Vehicle::class, $apartment]),
            'mask' => static fn (string $plate): string => PlateNormalizer::mask($plate),
        ])->title(__('parking.mine.title'));
    }

    private function ownVehicle(string $vehicleId): Vehicle
    {
        $apartment = $this->myApartment();
        abort_if($apartment === null, 403);

        return Vehicle::query()->current()->where('apartment_id', $apartment->id)->findOrFail($vehicleId);
    }
}
