<?php

declare(strict_types=1);

namespace App\Livewire\Heating;

use App\Enums\HeatingStatus;
use App\Enums\HeatingTriage;
use App\Models\Apartment;
use App\Models\HeatingReport;
use App\Models\Room;
use App\Models\User;
use App\Services\Heating\HeatingReportService;
use App\Services\Heating\RiserLabel;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Report sheet (DESIGN-SYSTEM §6.4, HEAT-002): rooms from the template → 4 states → "Saglabāt" (≤4 taps with
 * "Visās telpās vienādi"); optional °C, triage hint and a private note (admin/elder only). Thank-you with the
 * riser count stays until "Labi"; undo for a few seconds. The apartment is ALWAYS the user's own approved
 * membership (never a client value). NO offline queue: offline, "Saglabāt" is disabled and the selection stays in
 * the browser (wire:model is deferred, nothing is sent until the user saves).
 */
final class ReportSheet extends Component
{
    /** @var array<string, string> room code => status */
    public array $states = [];

    public ?string $temperature = null;

    public ?string $triage = null;

    public string $note = '';

    public bool $showExtras = false;

    #[Locked]
    public ?string $submission = null;

    #[Locked]
    public int $confirmedCount = 0;

    #[Locked]
    public bool $undone = false;

    public function allSame(string $status): void
    {
        if (HeatingStatus::tryFrom($status) === null) {
            return;
        }

        foreach ($this->rooms() as $room) {
            $this->states[$room['code']] = $status;
        }
    }

    public function toggleExtras(): void
    {
        $this->showExtras = ! $this->showExtras;
    }

    public function save(HeatingReportService $service): void
    {
        $this->resetErrorBag();
        $apartment = $this->apartment();
        abort_if($apartment === null, 403);
        Gate::authorize('create', [HeatingReport::class, $apartment]);

        $key = 'heating-report:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->addError('states', __('heating.report.rate_limited'));

            return;
        }
        RateLimiter::hit($key, 3600);

        $states = array_filter($this->states, static fn ($v): bool => is_string($v) && $v !== '');
        $result = $service->submit(
            $this->user(),
            $apartment,
            $states,
            $this->temperature,
            $this->triage,
            $this->note !== '' ? $this->note : null,
        );

        $this->submission = $result->id;
        $this->confirmedCount = $result->apartmentsOnMyRiser;
        $this->undone = false;
        $this->dispatch('heating-reported');
    }

    public function undo(HeatingReportService $service): void
    {
        if ($this->submission !== null && $service->undo($this->user(), $this->submission)) {
            $this->undone = true;
        }
        $this->submission = null;
    }

    /**
     * "Labi" — close the thank-you and reset the form.
     */
    public function done(): void
    {
        $this->reset(['states', 'temperature', 'triage', 'note', 'showExtras', 'submission', 'confirmedCount', 'undone']);
        $this->dispatch('mp-sheet-close');
    }

    public function render(): View
    {
        $apartment = $this->apartment();

        return view('livewire.heating.report-sheet', [
            'apartment' => $apartment,
            'rooms' => $apartment !== null ? $this->rooms() : collect(),
            'statuses' => HeatingStatus::cases(),
            'triages' => HeatingTriage::cases(),
            'canReport' => $apartment !== null && Gate::allows('create', [HeatingReport::class, $apartment]),
            'undoSeconds' => (int) config('majas.heating.undo_seconds_ui', 8),
        ]);
    }

    /**
     * @return Collection<int, array{code: string, label: string, towel: bool}>
     */
    private function rooms(): Collection
    {
        $apartment = $this->apartment();
        if ($apartment === null) {
            return collect();
        }

        return Room::query()->where('apartment_id', $apartment->id)->orderBy('sort')->get()
            ->map(fn (Room $r): array => [
                'code' => $r->code,
                'label' => (string) $r->getTranslation('label', app()->getLocale()) ?: RiserLabel::room($r->code),
                'towel' => $r->towel_dryer,
            ]);
    }

    private function apartment(): ?Apartment
    {
        $membership = $this->user()->approvedMembershipFor(CurrentBuilding::get());

        return $membership !== null ? Apartment::query()->find($membership->apartment_id) : null;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
