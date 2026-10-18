<?php

declare(strict_types=1);

namespace App\Livewire\Outage;

use App\Enums\IncidentKind;
use App\Models\Apartment;
use App\Models\Incident;
use App\Models\User;
use App\Services\Outages\IncidentService;
use App\Services\Outages\OutageScopeResolver;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Vai ir ūdens?" (DESIGN-SYSTEM §6.5, HEAT-004): Nav aukstā ūdens / Nav karstā ūdens / Nav siltuma → "Kopš kura
 * laika?" → (room question only when several risers of the kind serve the apartment) → auto-join the open
 * incident. Residents never pick a riser. The apartment is the user's own approved membership.
 */
final class ReportSheet extends Component
{
    public ?string $kind = null;

    public string $since = 'now';

    public ?string $answer = null;

    #[Locked]
    public ?int $apartments = null;

    #[Locked]
    public bool $joinedNotice = false;

    /** @var list<string> */
    #[Locked]
    public array $incidentIds = [];

    public function choose(string $kind): void
    {
        $this->kind = IncidentKind::tryFrom($kind)?->value;
        $this->answer = null;
    }

    public function setSince(string $since): void
    {
        $this->since = in_array($since, ['now', 'hour', 'morning'], true) ? $since : 'now';
    }

    public function submit(IncidentService $service): void
    {
        $this->resetErrorBag();
        $apartment = $this->apartment();
        abort_if($apartment === null, 403);
        Gate::authorize('report', [Incident::class, $apartment]);

        $kind = IncidentKind::tryFrom((string) $this->kind);
        if ($kind === null) {
            $this->addError('kind', __('outage.report.choose_kind'));

            return;
        }

        $key = 'outage-report:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->addError('kind', __('heating.report.rate_limited'));

            return;
        }
        RateLimiter::hit($key, 3600);

        $result = $service->report($this->user(), $apartment, $kind, $this->answer, in_array($this->since, ['now', 'hour', 'morning'], true) ? $this->since : 'now');

        $this->apartments = $result->apartments;
        $this->joinedNotice = $result->joinedNotice();
        $this->incidentIds = $result->incidents->pluck('id')->all();
    }

    public function again(): void
    {
        $this->reset();
        $this->dispatch('mp-sheet-close');
    }

    public function render(OutageScopeResolver $resolver): View
    {
        $apartment = $this->apartment();
        $kind = IncidentKind::tryFrom((string) $this->kind);
        $scope = $apartment !== null && $kind !== null ? $resolver->resolve($apartment, $kind) : null;

        return view('livewire.outage.report-sheet', [
            'apartment' => $apartment,
            'kinds' => IncidentKind::cases(),
            'options' => $scope !== null ? $scope->options : [],
            'canReport' => $apartment !== null && Gate::allows('report', [Incident::class, $apartment]),
            'building' => CurrentBuilding::get(),
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
