<?php

declare(strict_types=1);

namespace App\Livewire\Parking;

use App\Livewire\Parking\Concerns\ParkingResident;
use App\Models\Sighting;
use App\Services\Parking\LookupResult;
use App\Services\Parking\SightingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Esmu viesis / Tas ir mūsu viesis" (PARK-003): a resident says a car reported as unknown is their guest. Open
 * reports of that plate (30 days) become `disputed` for the officer. The answer is always the same neutral
 * sentence — it never reveals whether a report existed — and it consumes the lookup quota.
 */
final class SightingDispute extends Component
{
    use ParkingResident;

    #[Locked]
    public ?string $state = null;

    #[Locked]
    public ?int $retryAfter = null;

    public function submit(string $plate = ''): void
    {
        Gate::authorize('dispute', Sighting::class);

        $result = app(SightingService::class)->dispute($this->user(), $plate);
        $this->state = $result->status;
        $this->retryAfter = $result->status === LookupResult::RATE_LIMITED ? $result->retryAfter : null;
    }

    public function render(): View
    {
        return view('livewire.parking.sighting-dispute', ['canDispute' => Gate::allows('dispute', Sighting::class)]);
    }
}
