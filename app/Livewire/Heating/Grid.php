<?php

declare(strict_types=1);

namespace App\Livewire\Heating;

use App\Auth\StepUpService;
use App\Enums\AggregateStatus;
use App\Enums\ManagerReportKind;
use App\Enums\SurveyRoundStatus;
use App\Http\Controllers\ManagerReportDownloadController;
use App\Models\Apartment;
use App\Models\HeatingReport;
use App\Models\ManagerReport;
use App\Models\Riser;
use App\Models\RiserStatus;
use App\Models\Section;
use App\Models\SurveyRound;
use App\Models\User;
use App\Services\Buildings\BuildingGrid;
use App\Services\Heating\HeatingGridQuery;
use App\Services\Heating\ManagerReportGenerator;
use App\Services\Heating\RiserReviewService;
use App\Services\Heating\SectionScope;
use App\Services\Heating\Views\HeatingSectionView;
use App\Services\Outages\OutageOverlay;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Siltums / Ūdens (DESIGN-SYSTEM §6.3, MVP-SPEC B.3/B.4): stairwell tabs + whole-building mini map, round chip,
 * mode switch, grid / list toggle, legend, riser summaries, "Ziņot" sheet; staff (building_admin, or the elder of
 * THIS stairwell) see per-apartment cells and can download the manager PDF. Everyone else — including an elder of
 * another stairwell — gets the resident read model (counts only). Polls every 60 s while visible.
 */
#[Layout('layouts::app')]
final class Grid extends Component
{
    #[Locked]
    public string $mode = 'heating';

    /** Selected stairwell NUMBER (not an id). */
    #[Url(as: 'k', except: 0)]
    public int $stairwell = 0;

    #[Url(as: 'v', except: 'grid')]
    public string $display = 'grid';

    #[Url(as: 'r', except: 'rolling')]
    public string $round = 'rolling';

    #[Locked]
    public bool $openReport = false;

    public ?string $flash = null;

    public function mount(string $mode = 'heating'): void
    {
        $this->mode = $mode === 'outage' ? 'outage' : 'heating';
        $this->openReport = request()->boolean('report');

        if ($this->stairwell === 0) {
            $this->stairwell = $this->myApartment()?->section->number
                ?? (int) Section::query()->orderBy('number')->value('number');
        }
    }

    public function selectStairwell(int $number): void
    {
        if (Section::query()->where('number', $number)->exists()) {
            $this->stairwell = $number;
        }
    }

    public function setDisplay(string $display): void
    {
        $this->display = $display === 'list' ? 'list' : 'grid';
    }

    public function setRound(string $key): void
    {
        $this->round = HeatingGridQuery::normalizeRoundKey($key);
    }

    /**
     * The report sheet saved (or undid) a report: re-render with the reporter's own report and stop treating the
     * page as "open the report sheet" (?report=1 is dropped from the address bar in resources/js/app.js).
     */
    #[On('heating-reported')]
    public function reported(): void
    {
        $this->openReport = false;
    }

    /**
     * "Mans radiators nav šajā stāvadā" — freezes the column until an admin decides.
     */
    public function dispute(int $riserId, RiserReviewService $reviews): void
    {
        $riser = Riser::query()->findOrFail($riserId);
        $reviews->dispute($this->user(), $riser);
        $this->flash = 'disputed';
    }

    /**
     * Staff: generate the manager report for this stairwell (step-up required) and open the signed PDF.
     */
    public function managerReport(ManagerReportGenerator $generator, StepUpService $stepUp): mixed
    {
        $section = $this->section();
        $user = $this->user();
        Gate::forUser($user)->authorize('generate', [ManagerReport::class, [(int) $section->id]]);

        if (! $stepUp->hasFreshFor($user)) {
            session()->put(StepUpService::INTENDED_KEY, route($this->mode === 'outage' ? 'building.water' : 'building.heating', CurrentBuilding::get(), absolute: false).'?k='.$section->number);
            session()->put(StepUpService::LEVEL_KEY, $stepUp->requiresMfa($user) ? 'mfa' : 'any');

            return $this->redirectRoute('step-up');
        }

        if (RateLimiter::tooManyAttempts('manager-report:'.$user->id, 10)) {
            $this->flash = 'report_limit';

            return null;
        }
        RateLimiter::hit('manager-report:'.$user->id, 3600);

        $roundId = str_starts_with($this->round, 'round:') ? (int) substr($this->round, 6) : null;
        $report = $generator->generate(
            $user,
            $this->mode === 'outage' ? ManagerReportKind::Outage : ManagerReportKind::Heating,
            SectionScope::fromIds([(int) $section->id]),
            roundId: $roundId,
        );

        return $this->redirect(ManagerReportDownloadController::url(CurrentBuilding::get(), $report));
    }

    public function render(BuildingGrid $grids, HeatingGridQuery $query, OutageOverlay $overlay): View
    {
        $building = CurrentBuilding::get();
        $user = $this->user();
        $section = $this->section();
        $mine = $this->myApartment();
        $sections = Section::query()->orderBy('number')->get(['id', 'number', 'floors', 'first_apartment_no', 'apartments_per_floor']);
        $structure = $grids->forCurrentBuilding([$section->number])['sections'][0];
        $this->round = HeatingGridQuery::normalizeRoundKey($this->round);

        $staff = Gate::forUser($user)->allows('viewApartments', [HeatingReport::class, $section]);
        /** @var HeatingSectionView $heating */
        $heating = $staff ? $query->staffSection($user, $section, $this->round) : $query->residentSection($section, $this->round);

        $rounds = SurveyRound::query()
            ->whereIn('status', [SurveyRoundStatus::Open->value, SurveyRoundStatus::Closed->value])
            ->orderByDesc('starts_at')->limit(4)->get()
            ->filter(fn (SurveyRound $r): bool => $r->covers((int) $section->id))
            ->values();

        $membership = $mine !== null ? $user->membershipFor($building) : null;
        $own = ! $staff && $mine !== null && (int) $mine->section_id === (int) $section->id
            ? $this->ownReport($user, $mine, $section, $this->round)
            : null;

        return view('livewire.heating.grid', [
            'building' => $building,
            'section' => $section,
            'sections' => $sections,
            'structure' => $structure,
            'heating' => $heating,
            'miniMap' => $query->buildingColumns($sections->pluck('floors', 'id')->map(fn ($f): int => (int) $f)->all(), $this->round),
            'staff' => $staff,
            'mine' => $mine,
            'own' => $own,
            'canReport' => $mine !== null && Gate::forUser($user)->allows('create', [HeatingReport::class, $mine]),
            'pending' => $membership?->isPending() ?? false,
            'rounds' => $rounds,
            'overlay' => $this->mode === 'outage' ? $overlay->forSection((int) $section->id, $structure) : null,
            'canGenerate' => Gate::forUser($user)->allows('generate', [ManagerReport::class, [(int) $section->id]]),
            'myRiserIds' => $mine !== null ? $mine->riserLinks()->pluck('riser_id')->map(fn ($id): int => (int) $id)->all() : [],
            'outagesOn' => $building->settings->moduleEnabled('outages'),
            'heatingOn' => $building->settings->moduleEnabled('heating'),
            'pollSeconds' => (int) config('majas.heating.poll_seconds', 60),
        ])->title(__($this->mode === 'outage' ? 'outage.title' : 'heating.title'));
    }

    /**
     * The signed-in reporter's OWN valid report for their apartment in this view (FIX-007.1). The grid reads the
     * projections, which a debounced job (RecomputeRiserStatus, ~10 s) refreshes — until then the reporter used to
     * see "Esiet pirmais" right after reporting. `pending` = the projection of this stairwell is older than the
     * report; the page then shows the own state at once and polls every few seconds until the projection caught up.
     * Only the person's own rows (reported_by_user_id), so no other resident's state is ever revealed.
     *
     * @return array{status: AggregateStatus, pending: bool}|null
     */
    private function ownReport(User $user, Apartment $mine, Section $section, string $roundKey): ?array
    {
        $reports = HeatingReport::query()
            ->where('apartment_id', $mine->id)
            ->where('reported_by_user_id', $user->id)
            ->when(
                str_starts_with($roundKey, 'round:'),
                fn ($q) => $q->active()->where('survey_round_id', (int) substr($roundKey, 6)),
                fn ($q) => $q->valid(),
            )
            ->get(['status', 'reported_at']);

        if ($reports->isEmpty()) {
            return null;
        }

        $status = AggregateStatus::worst($reports->map(fn (HeatingReport $r): AggregateStatus => AggregateStatus::fromHeating($r->status)));
        $projectedAt = RiserStatus::query()->where('section_id', $section->id)->where('round_key', $roundKey)->max('updated_at');
        $latest = $reports->max('reported_at');

        return [
            'status' => $status ?? AggregateStatus::None,
            'pending' => $projectedAt === null || CarbonImmutable::parse($projectedAt)->lt($latest),
        ];
    }

    private function section(): Section
    {
        return Section::query()->where('number', $this->stairwell)->first()
            ?? Section::query()->orderBy('number')->firstOrFail();
    }

    private function myApartment(): ?Apartment
    {
        $membership = $this->user()->membershipFor(CurrentBuilding::get());

        return $membership !== null ? Apartment::query()->with('section')->find($membership->apartment_id) : null;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
