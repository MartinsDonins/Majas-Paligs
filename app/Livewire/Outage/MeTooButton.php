<?php

declare(strict_types=1);

namespace App\Livewire\Outage;

use App\Models\Incident;
use App\Models\IncidentConfirmation;
use App\Models\User;
use App\Services\Outages\IncidentService;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Man arī / У меня тоже" — one-tap confirmation with the count "Pieteikuši 5 dzīvokļi"; idempotent per apartment
 * (UNIQUE(incident_id, apartment_id)). Only apartments inside the incident's scope may confirm.
 */
final class MeTooButton extends Component
{
    #[Locked]
    public string $incidentId = '';

    public function confirm(IncidentService $service): void
    {
        $incident = Incident::query()->findOrFail($this->incidentId);
        Gate::authorize('confirm', $incident);
        $service->meToo($this->user(), $incident);
    }

    public function render(): View
    {
        $incident = Incident::query()->withCount('confirmations')->findOrFail($this->incidentId);
        $membership = $this->user()->approvedMembershipFor(CurrentBuilding::get());

        return view('livewire.outage.me-too-button', [
            'count' => (int) $incident->confirmations_count,
            'canConfirm' => Gate::allows('confirm', $incident),
            'confirmed' => $membership !== null && IncidentConfirmation::query()
                ->where('incident_id', $incident->id)->where('apartment_id', $membership->apartment_id)->exists(),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
