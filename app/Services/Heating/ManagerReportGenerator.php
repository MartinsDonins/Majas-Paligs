<?php

declare(strict_types=1);

namespace App\Services\Heating;

use App\Audit\AuditLogger;
use App\Enums\AggregateStatus;
use App\Enums\IncidentOrigin;
use App\Enums\ManagerReportKind;
use App\Enums\RiserKind;
use App\Models\Apartment;
use App\Models\ApartmentCell;
use App\Models\HeatingReport;
use App\Models\Incident;
use App\Models\ManagerReport;
use App\Models\Riser;
use App\Models\Section;
use App\Models\SurveyRound;
use App\Models\User;
use App\Support\CounterService;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Manager package (HEAT-005, PLANNING §4.6, MVP-SPEC B.6/B.7 AC 8): stable gap-free number MP-YYYY-NNNN,
 * aggregates only. ALWAYS: per riser status, confirmed, distinct apartments, floors covered (a count), coverage,
 * triage counts incl. "viss stāvads auksts" (whole-riser-under), confidence, timeline, column-level snapshot.
 * ONLY with share_with_manager.heating_floors: floors reported per riser + per-floor grid snapshot. ONLY with
 * apartment_numbers (requires heating_floors): apartment numbers. NEVER reporter identities or private notes.
 * Disputed risers are excluded. The stored snapshot is exactly what the PDF/CSV render.
 */
final class ManagerReportGenerator
{
    public function __construct(
        private readonly RiserStatusProjector $projector,
        private readonly CounterService $counters,
    ) {}

    public function generate(
        User $user,
        ManagerReportKind $kind,
        SectionScope $scope,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?int $roundId = null,
    ): ManagerReport {
        $sectionIds = $scope->resolvedIds();
        Gate::forUser($user)->authorize('generate', [ManagerReport::class, $sectionIds]);

        $building = CurrentBuilding::get();
        $sharing = ReportSharing::fromSettings($building->settings);
        $round = $roundId !== null ? SurveyRound::query()->findOrFail($roundId) : null;
        $to ??= $round?->ends_at->min(now()) ?? CarbonImmutable::now();
        $from ??= $round !== null ? $round->starts_at : $to->subDays($building->settings->adhocReportValidDays);

        return DB::transaction(function () use ($user, $kind, $sectionIds, $sharing, $round, $from, $to, $building): ManagerReport {
            $number = $this->counters->nextNumber('manager_report');
            $generatedAt = CarbonImmutable::now();

            $snapshot = [
                'version' => 1,
                'number' => $number,
                'kind' => $kind->value,
                'generated_at' => $generatedAt->toIso8601String(),
                'building' => [
                    'name' => $building->name,
                    'address' => $building->address_line.', '.$building->city,
                    'manager_name' => $building->manager_name,
                    'emergency_phone' => $building->manager_emergency_phone,
                ],
                'period' => [
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                    'round' => $round?->getTranslations('title'),
                ],
                'sections' => Section::query()->whereIn('id', $sectionIds)->orderBy('number')->pluck('number')->map(fn ($n): int => (int) $n)->all(),
                'sharing' => $sharing->toArray(),
            ];

            $snapshot += $kind === ManagerReportKind::Heating
                ? $this->heating($sectionIds, $sharing, $round, $from, $to)
                : $this->outages($sectionIds, $from, $to);

            $report = ManagerReport::query()->create([
                'number' => $number,
                'kind' => $kind,
                'section_ids' => $sectionIds,
                'params' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'round_id' => $round?->id],
                'sharing' => $sharing->toArray(),
                'snapshot' => $snapshot,
                'generated_by' => $user->id,
                'generated_at' => $generatedAt,
            ]);

            AuditLogger::record('manager_report.generate', $report, data: [
                'report_number' => $number,
                'kind' => $kind->value,
                'sections' => $snapshot['sections'],
                'heating_floors' => $sharing->heatingFloors,
                'apartment_numbers' => $sharing->apartmentNumbers,
            ]);

            return $report;
        });
    }

    /**
     * @param  list<int>  $sectionIds
     * @return array<string, mixed>
     */
    private function heating(array $sectionIds, ReportSharing $sharing, ?SurveyRound $round, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $apartments = Apartment::query()->whereIn('section_id', $sectionIds)->get(['id', 'number', 'floor', 'position', 'section_id'])->keyBy('id');

        $all = HeatingReport::query()
            ->whereNull('hidden_at')
            ->whereIn('apartment_id', $apartments->keys())
            ->when($round !== null, fn ($q) => $q->where('survey_round_id', $round?->id)->whereNull('superseded_at'))
            ->when($round === null, fn ($q) => $q->whereBetween('reported_at', [$from, $to]))
            ->orderBy('reported_at')
            ->get(['apartment_id', 'room_id', 'status', 'triage', 'reported_at']);

        // Latest report per (apartment, room) inside the period.
        /** @var Collection<int, HeatingReport> $latest */
        $latest = $all->keyBy(fn (HeatingReport $r): string => $r->apartment_id.':'.$r->room_id)->values();

        $aggregates = $this->projector->aggregateReports($latest, $sectionIds);
        $risers = Riser::query()->whereIn('id', array_keys($aggregates))->with('section:id,number')->orderBy('code')->get()->keyBy('id');
        $structure = $this->projector->structure();

        $riserRows = [];
        $excluded = 0;
        $wholeRiserUnder = 0;
        foreach ($risers as $riserId => $riser) {
            $agg = $aggregates[$riserId];
            if ($agg->disputed) {
                $excluded++;

                continue;
            }
            if ($agg->wholeRiserUnder) {
                $wholeRiserUnder++;
            }

            $row = [
                'code' => $riser->code,
                'section' => $riser->section->number,
                'kind' => $riser->kind->value,
                'position' => $riser->position,
                'status' => $agg->status->value,
                'confirmed' => $agg->confirmed,
                'distinct_apartments' => $agg->distinctApartments,
                'floors_covered' => $agg->floorsCovered,
                'coverage_total' => $agg->coverageTotal,
                'status_counts' => $agg->statusCounts,
                'whole_riser_under' => $agg->wholeRiserUnder,
                'conflict' => $agg->conflict,
                'checked_state' => $agg->checkedState,
                'confidence' => $riser->confidence->value,
            ];

            if ($sharing->heatingFloors) {
                $row['floors_reported'] = $agg->floorsReported;
            }
            if ($sharing->apartmentNumbers) {
                $row['apartment_numbers'] = array_values(array_map(
                    fn (int $id): int => (int) $apartments->get($id)?->number,
                    $agg->apartmentIds,
                ));
            }

            $riserRows[] = $row;
        }

        // Triage counts (apartments per hint) + the whole-riser-under count.
        $triage = ['air_suspected' => 0, 'valve_closed' => 0, 'whole_flat' => 0, 'unknown' => 0];
        foreach ($latest->whereNotNull('triage')->groupBy(fn (HeatingReport $r): string => (string) $r->triage?->value) as $value => $rows) {
            $triage[$value] = $rows->pluck('apartment_id')->unique()->count();
        }
        $triage['whole_riser_under'] = $wholeRiserUnder;

        $timeline = $all->groupBy(fn (HeatingReport $r): string => $r->reported_at->setTimezone('Europe/Riga')->format('Y-m-d'))
            ->map(fn (Collection $rows, string $date): array => ['date' => $date, 'reports' => $rows->count(), 'apartments' => $rows->pluck('apartment_id')->unique()->count()])
            ->values()
            ->all();

        $out = [
            'coverage' => [
                'apartments_total' => $apartments->count(),
                'apartments_reporting' => $latest->pluck('apartment_id')->unique()->count(),
            ],
            'risers' => $riserRows,
            'excluded_disputed' => $excluded,
            'triage' => $triage,
            'timeline' => $timeline,
            'columns' => $this->columns($sectionIds, $aggregates, $structure),
        ];

        if ($sharing->heatingFloors) {
            $out['floor_grid'] = $this->floorGrid($sectionIds, $latest, $apartments, $sharing->apartmentNumbers);
        }

        return $out;
    }

    /**
     * Column-level snapshot (always): worst confirmed state per (stairwell, position).
     *
     * @param  list<int>  $sectionIds
     * @param  array<int, RiserAggregate>  $aggregates
     * @param  array{risers: array<int, array{id: int, section_id: int, code: string, kind: string, position: ?int, positions: list<int>, coverage: int, apartments: list<int>}>}  $structure
     * @return list<array{section: int, position: int, status: ?string, confirmed: bool, apartments: int}>
     */
    private function columns(array $sectionIds, array $aggregates, array $structure): array
    {
        $sections = Section::query()->whereIn('id', $sectionIds)->orderBy('number')->get();
        $out = [];
        foreach ($sections as $section) {
            for ($p = 1; $p <= $section->apartments_per_floor; $p++) {
                $confirmed = [];
                $unconfirmed = [];
                $apartments = [];
                foreach ($structure['risers'] as $riserId => $riser) {
                    if ($riser['section_id'] !== (int) $section->id || $riser['kind'] !== RiserKind::Heating->value || ! in_array($p, $riser['positions'], true)) {
                        continue;
                    }
                    $agg = $aggregates[$riserId] ?? null;
                    if ($agg === null || $agg->disputed || $agg->distinctApartments === 0) {
                        continue;
                    }
                    foreach ($agg->apartmentIds as $a) {
                        $apartments[$a] = true;
                    }
                    if ($agg->confirmed) {
                        $confirmed[] = $agg->status;
                    } else {
                        $unconfirmed[] = $agg->status;
                    }
                }
                $worst = AggregateStatus::worst($confirmed);
                $out[] = [
                    'section' => $section->number,
                    'position' => $p,
                    'status' => ($worst ?? AggregateStatus::worst($unconfirmed))?->value,
                    'confirmed' => $worst !== null,
                    'apartments' => count($apartments),
                ];
            }
        }

        return $out;
    }

    /**
     * Per-floor grid snapshot — only when the house shares heating floors; apartment numbers only when it also
     * shares apartment numbers.
     *
     * @param  list<int>  $sectionIds
     * @param  Collection<int, HeatingReport>  $latest
     * @param  Collection<int, Apartment>  $apartments
     * @return list<array{section: int, floors: list<array{floor: int, cells: list<array{position: int, status: ?string, apartment?: int}>}>}>
     */
    private function floorGrid(array $sectionIds, Collection $latest, Collection $apartments, bool $withNumbers): array
    {
        $worst = [];
        foreach ($latest as $r) {
            $current = $worst[$r->apartment_id] ?? null;
            $status = AggregateStatus::fromHeating($r->status);
            if ($current === null || $status->severity() > $current->severity()) {
                $worst[$r->apartment_id] = $status;
            }
        }

        $cells = ApartmentCell::query()->whereIn('section_id', $sectionIds)->get()->groupBy('section_id');
        $out = [];
        foreach (Section::query()->whereIn('id', $sectionIds)->orderBy('number')->get() as $section) {
            $floors = [];
            for ($f = $section->floors; $f >= 1; $f--) {
                $row = [];
                foreach ($cells->get($section->id, collect())->where('floor', $f)->sortBy('position') as $cell) {
                    $entry = ['position' => (int) $cell->position, 'status' => ($worst[$cell->apartment_id] ?? null)?->value];
                    if ($withNumbers) {
                        $entry['apartment'] = (int) $apartments->get($cell->apartment_id)?->number;
                    }
                    $row[] = $entry;
                }
                $floors[] = ['floor' => $f, 'cells' => $row];
            }
            $out[] = ['section' => $section->number, 'floors' => $floors];
        }

        return $out;
    }

    /**
     * Outage report: incidents of the period in scope (aggregates: confirmations as a count, never who).
     *
     * @param  list<int>  $sectionIds
     * @return array<string, mixed>
     */
    private function outages(array $sectionIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $incidents = Incident::query()
            ->withCount('confirmations')
            ->with(['riser:id,code', 'section:id,number'])
            ->where('started_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $from))
            ->where(fn ($q) => $q->whereIn('section_id', $sectionIds)->orWhereNull('section_id'))
            ->orderBy('started_at')
            ->get();

        return [
            'incidents' => $incidents->map(fn (Incident $i): array => [
                'kind' => $i->kind->value,
                'origin' => $i->origin->value,
                'scope' => $this->scopeLabel($i),
                'status' => $i->status->value,
                'started_at' => $i->started_at->toIso8601String(),
                'expected_end_at' => $i->expected_end_at?->toIso8601String(),
                'resolved_at' => $i->resolved_at?->toIso8601String(),
                'confirmations' => (int) $i->confirmations_count,
                'floors' => $i->origin === IncidentOrigin::Notice ? $i->floorRange() : null,
                'manager_ref' => $i->manager_ref,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{type: string, section: ?int, riser: ?string, position: ?int}
     */
    private function scopeLabel(Incident $i): array
    {
        return [
            'type' => explode(':', $i->scope_key)[0],
            'section' => $i->section?->number,
            'riser' => $i->riser?->code,
            'position' => $i->column_position,
        ];
    }
}
