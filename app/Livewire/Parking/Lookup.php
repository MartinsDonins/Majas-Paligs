<?php

declare(strict_types=1);

namespace App\Livewire\Parking;

use App\Enums\LookupReason;
use App\Livewire\Parking\Concerns\ParkingResident;
use App\Models\PlateLookup;
use App\Services\Parking\Completeness;
use App\Services\Parking\LookupResult;
use App\Services\Parking\PlateLookupService;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Stāvvieta — "Pārbaudīt auto" (DESIGN-SYSTEM §6.6, MVP-SPEC C.3): legal banner, huge plate input, reason chips,
 * neutral result card ("Reģistrēts šajā mājā" / "Nav atrasts reģistrā" + list completeness), "Šī pārbaude tiek
 * reģistrēta", friendly countdown when the quota is used up. The plate arrives as an action argument and is
 * never kept in component state; the result is a single yes/no.
 */
#[Layout('layouts::app')]
final class Lookup extends Component
{
    use ParkingResident;

    public string $reason = 'unknown_car';

    #[Locked]
    public ?bool $registered = null;

    #[Locked]
    public ?int $retryAfter = null;

    #[Locked]
    public ?string $error = null;

    public function setReason(string $reason): void
    {
        $this->reason = in_array($reason, self::reasons(), true) ? $reason : 'unknown_car';
    }

    public function check(string $plate = ''): void
    {
        Gate::authorize('lookup', PlateLookup::class);
        $this->error = null;
        $this->registered = null;

        $result = app(PlateLookupService::class)->lookup($this->user(), $plate, LookupReason::tryFrom($this->reason) ?? LookupReason::UnknownCar);

        match ($result->status) {
            LookupResult::INVALID => $this->error = 'invalid',
            LookupResult::RATE_LIMITED => $this->retryAfter = $result->retryAfter,
            default => [$this->registered, $this->retryAfter] = [$result->registered, null],
        };
    }

    public function again(): void
    {
        $this->registered = null;
        $this->error = null;
    }

    /**
     * @return list<string>
     */
    public static function reasons(): array
    {
        return [LookupReason::Blocking->value, LookupReason::UnknownCar->value, LookupReason::OwnCheck->value, LookupReason::Other->value];
    }

    public function render(Completeness $completeness): View
    {
        return view('livewire.parking.lookup', [
            'building' => CurrentBuilding::get(),
            'canLookup' => Gate::allows('lookup', PlateLookup::class),
            'completeness' => $completeness->forBuilding(),
            'reasons' => self::reasons(),
        ])->title(__('parking.lookup.title'));
    }
}
