<?php

declare(strict_types=1);

namespace App\Livewire\Outage;

use App\Models\Incident;
use App\Services\Outages\IncidentPresenter;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Outage detail (/b/{slug}/incidents/{ulid}): status, scope, how many apartments confirmed, the manager's reply in
 * one plain sentence, "Man arī". Aggregates only. An incident of another building is invisible (scope → 404).
 */
#[Layout('layouts::app')]
final class Detail extends Component
{
    #[Locked]
    public string $incidentId = '';

    public function mount(string $incident): void
    {
        $model = Incident::query()->findOrFail($incident);
        Gate::authorize('view', $model);
        $this->incidentId = $model->id;
    }

    public function render(IncidentPresenter $presenter): View
    {
        $incident = Incident::query()->withCount('confirmations')->with(['riser:id,code', 'section:id,number'])->findOrFail($this->incidentId);
        Gate::authorize('view', $incident);
        $locale = app()->getLocale();

        return view('livewire.outage.detail', [
            'building' => CurrentBuilding::get(),
            'incident' => $incident,
            'scope' => $presenter->scope($incident),
            'reason' => $incident->getTranslation('reason', $locale) ?: null,
            'reply' => $incident->getTranslation('manager_reply', $locale) ?: null,
        ])->title($incident->kind->label());
    }
}
