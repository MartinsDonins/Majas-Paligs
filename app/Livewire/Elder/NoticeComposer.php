<?php

declare(strict_types=1);

namespace App\Livewire\Elder;

use App\Enums\IncidentKind;
use App\Enums\IncidentOrigin;
use App\Models\Incident;
use App\Models\Riser;
use App\Models\Section;
use App\Models\User;
use App\Services\Heating\RiserLabel;
use App\Services\Outages\IncidentPresenter;
use App\Services\Outages\IncidentService;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * Outage notice composer (HEAT-008): the elder targets a riser, a column or their stairwell (building_admin also the
 * whole building), writes the reason in LV and RU, optionally the floor range and the expected end. The banner then
 * shows only to affected apartments. Open notices of the elder's stairwells can be closed here.
 */
final class NoticeComposer extends Component
{
    public string $kind = 'water_cold';

    public string $target = 'section';

    public ?int $stairwell = null;

    public ?int $position = null;

    public ?int $riser = null;

    public ?int $floorsFrom = null;

    public ?int $floorsTo = null;

    public string $reasonLv = '';

    public string $reasonRu = '';

    public ?string $expectedEnd = null;

    public bool $posted = false;

    public function mount(): void
    {
        $this->stairwell = $this->sections()->first()?->number;
    }

    public function post(IncidentService $service): void
    {
        $this->validate([
            'kind' => 'required|in:'.implode(',', IncidentKind::values()),
            'target' => 'required|in:building,section,column,riser',
            'stairwell' => 'nullable|integer',
            'position' => 'nullable|integer|min:1|max:12',
            'riser' => 'nullable|integer',
            'floorsFrom' => 'nullable|integer|min:1|max:40',
            'floorsTo' => 'nullable|integer|min:1|max:40',
            'reasonLv' => 'required|string|max:300',
            'reasonRu' => 'required|string|max:300',
            'expectedEnd' => 'nullable|date',
        ]);

        $section = $this->stairwell !== null ? Section::query()->where('number', $this->stairwell)->first() : null;

        $service->postNotice($this->user(), IncidentKind::from($this->kind), [
            'target' => $this->target,
            'section_id' => $section?->id,
            'position' => $this->position,
            'riser_id' => $this->riser,
            'floors_from' => $this->floorsFrom,
            'floors_to' => $this->floorsTo,
            'reason_lv' => $this->reasonLv,
            'reason_ru' => $this->reasonRu,
            'expected_end_at' => $this->expectedEnd,
        ]);

        $this->reset(['reasonLv', 'reasonRu', 'expectedEnd', 'floorsFrom', 'floorsTo', 'position', 'riser']);
        $this->posted = true;
    }

    public function resolve(string $incidentId, IncidentService $service): void
    {
        $incident = Incident::query()->findOrFail($incidentId);
        $service->resolve($this->user(), $incident);
    }

    public function render(IncidentPresenter $presenter): View
    {
        $user = $this->user();
        $building = CurrentBuilding::get();
        $isAdmin = $user->isBuildingAdmin($building);
        $sections = $this->sections();
        $section = $sections->firstWhere('number', $this->stairwell);
        $kind = IncidentKind::tryFrom($this->kind) ?? IncidentKind::WaterCold;

        $notices = Incident::query()->open()->where('origin', IncidentOrigin::Notice->value)
            ->with(['riser:id,code', 'section:id,number'])
            ->when(! $isAdmin, fn ($q) => $q->whereIn('section_id', $sections->pluck('id')))
            ->orderByDesc('started_at')->get();

        return view('livewire.elder.notice-composer', [
            'kinds' => IncidentKind::cases(),
            'sections' => $sections,
            'isAdmin' => $isAdmin,
            'positions' => $section !== null ? range(1, $section->apartments_per_floor) : [],
            'risers' => $section !== null
                ? Riser::query()->where('section_id', $section->id)->where('kind', $kind->riserKind()->value)->orderBy('code')->get(['id', 'code'])
                    ->mapWithKeys(fn (Riser $r): array => [$r->id => RiserLabel::for($r->code)])->all()
                : [],
            'notices' => $notices,
            'presenter' => $presenter,
            'enabled' => $building->settings->moduleEnabled('outages'),
        ]);
    }

    /**
     * Stairwells the user may target: elder → own; building_admin → all.
     *
     * @return Collection<int, Section>
     */
    private function sections(): Collection
    {
        $user = $this->user();
        $building = CurrentBuilding::get();

        return Section::query()
            ->when(! $user->isBuildingAdmin($building), fn ($q) => $q->whereIn('id', $user->elderSectionIds($building)))
            ->orderBy('number')->get();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
