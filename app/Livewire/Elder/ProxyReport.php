<?php

declare(strict_types=1);

namespace App\Livewire\Elder;

use App\Enums\BuildingRoleType;
use App\Enums\HeatingStatus;
use App\Enums\ReportSource;
use App\Models\Apartment;
use App\Models\BuildingRole;
use App\Models\HeatingReport;
use App\Models\Room;
use App\Models\User;
use App\Services\Heating\HeatingReportService;
use App\Tenancy\CurrentBuilding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Proxy / phone report (HEAT-008, DESIGN-SYSTEM §6.9): an elder (or volunteer) enters a neighbour's radiator states
 * for an apartment of THEIR OWN stairwell, marked "pēc telefona" / "klātienē". Audit-logged (heating.proxy_report).
 */
final class ProxyReport extends Component
{
    public ?int $apartmentNumber = null;

    public string $source = 'phone';

    /** @var array<string, string> */
    public array $states = [];

    public ?int $savedFor = null;

    public function updatedApartmentNumber(): void
    {
        $this->states = [];
        $this->savedFor = null;
    }

    public function save(HeatingReportService $service): void
    {
        $this->resetErrorBag();
        $apartment = $this->apartment();
        if ($apartment === null) {
            $this->addError('apartmentNumber', __('elder.proxy.choose_apartment'));

            return;
        }
        Gate::authorize('proxy', [HeatingReport::class, $apartment]);

        $service->submit(
            $this->user(),
            $apartment,
            array_filter($this->states, static fn ($v): bool => is_string($v) && $v !== ''),
            source: $this->source === 'proxy' ? ReportSource::Proxy : ReportSource::Phone,
        );

        $this->savedFor = $apartment->number;
        $this->states = [];
        $this->apartmentNumber = null;
    }

    public function render(): View
    {
        $sectionIds = $this->sectionIds();
        $apartment = $this->apartment();

        return view('livewire.elder.proxy-report', [
            'apartments' => Apartment::query()->active()->whereIn('section_id', $sectionIds)->orderBy('number')->pluck('number')->all(),
            'rooms' => $apartment !== null ? Room::query()->where('apartment_id', $apartment->id)->orderBy('sort')->get() : collect(),
            'statuses' => HeatingStatus::cases(),
            'enabled' => CurrentBuilding::get()->settings->moduleEnabled('heating'),
        ]);
    }

    /**
     * Apartment by number, only inside the caller's own stairwells (elder or volunteer).
     */
    private function apartment(): ?Apartment
    {
        if ($this->apartmentNumber === null) {
            return null;
        }

        return Apartment::query()->active()->where('number', $this->apartmentNumber)->whereIn('section_id', $this->sectionIds())->first();
    }

    /**
     * @return list<int>
     */
    private function sectionIds(): array
    {
        return $this->user()->rolesIn(CurrentBuilding::get())
            ->filter(fn (BuildingRole $r): bool => in_array($r->role, [BuildingRoleType::SectionElder, BuildingRoleType::Volunteer], true) && $r->section_id !== null)
            ->map(fn (BuildingRole $r): int => (int) $r->section_id)
            ->unique()->values()->all();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
