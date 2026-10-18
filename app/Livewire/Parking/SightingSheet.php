<?php

declare(strict_types=1);

namespace App\Livewire\Parking;

use App\Livewire\Parking\Concerns\ParkingResident;
use App\Models\ParkingLot;
use App\Models\Sighting;
use App\Services\Parking\SightingResult;
use App\Services\Parking\SightingService;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * "Ziņot par svešu auto" (DESIGN-SYSTEM §6.8, PARK-003): lot, time (prefilled), plate, optional in-app photo
 * (converted to JPEG in the browser; "Nefotografējiet cilvēkus · Foto tiks dzēsts pēc 30 dienām"). The
 * confirmation is ALWAYS the neutral "Paldies, ziņojums nodots stāvvietas atbildīgajam" — never registered / not
 * registered. Daily limits answer calmly. "Mani ziņojumi" shows time, lot and a neutral state only.
 */
#[Layout('layouts::app')]
final class SightingSheet extends Component
{
    use ParkingResident;
    use WithFileUploads;

    public ?int $lotId = null;

    public string $seenAt = '';

    public string $country = 'LV';

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    #[Locked]
    public ?string $state = null;

    #[Locked]
    public bool $photoDropped = false;

    #[Locked]
    public ?string $photoError = null;

    #[Locked]
    public ?int $retryAfter = null;

    public function mount(): void
    {
        $this->lotId = ParkingLot::query()->orderBy('sort')->orderBy('name')->value('id');
        $this->seenAt = now('Europe/Riga')->format('Y-m-d\TH:i');
    }

    public function updatedPhoto(): void
    {
        $this->photoError = null;

        try {
            $this->validate(['photo' => ['nullable', 'file', 'max:8192']]);
        } catch (Throwable) {
            $this->removePhoto();
            $this->photoError = 'size';
        }
    }

    public function removePhoto(): void
    {
        if ($this->photo instanceof TemporaryUploadedFile) {
            $this->photo->delete();
        }
        $this->photo = null;
    }

    public function submit(string $plate = ''): void
    {
        Gate::authorize('create', Sighting::class);
        $apartment = $this->myApartment();
        abort_if($apartment === null, 403);

        $lot = ParkingLot::query()->findOrFail((int) $this->lotId);
        $seen = $this->parseSeenAt();
        $path = $this->photo instanceof TemporaryUploadedFile ? $this->photo->getRealPath() : null;

        $result = app(SightingService::class)->submit($this->user(), $apartment, $lot, $plate, $this->country, $seen, is_string($path) && $path !== '' ? $path : null);

        $this->photoError = $result->status === SightingResult::PHOTO_REJECTED ? $result->photoReason : null;
        $this->retryAfter = $result->status === SightingResult::RATE_LIMITED ? $result->retryAfter : null;
        $this->state = $result->status;
        $this->photoDropped = $result->photoDropped;

        if ($result->status === SightingResult::SUBMITTED) {
            $this->removePhoto();
        }
    }

    public function again(): void
    {
        $this->state = null;
        $this->photoDropped = false;
        $this->photoError = null;
        $this->seenAt = now('Europe/Riga')->format('Y-m-d\TH:i');
    }

    public function render(): View
    {
        $mine = Sighting::query()->where('reporter_user_id', $this->user()->id)
            ->with('lot:id,name')->orderByDesc('seen_at')->limit(10)
            ->get(['id', 'parking_lot_id', 'seen_at', 'status']);

        return view('livewire.parking.sighting-sheet', [
            'building' => CurrentBuilding::get(),
            'lots' => ParkingLot::query()->orderBy('sort')->orderBy('name')->get(['id', 'name']),
            'canReport' => Gate::allows('create', Sighting::class) && $this->myApartment() !== null,
            'mine' => $mine,
        ])->title(__('parking.sighting.title'));
    }

    private function parseSeenAt(): ?CarbonImmutable
    {
        try {
            return $this->seenAt !== '' ? CarbonImmutable::parse($this->seenAt, 'Europe/Riga')->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
