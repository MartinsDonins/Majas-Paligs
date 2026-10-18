<?php

declare(strict_types=1);

namespace App\Services\Heating;

use App\Enums\AggregateStatus;
use App\Enums\RiserKind;
use App\Enums\SurveyRoundStatus;
use App\Models\Apartment;
use App\Models\ColumnStatus;
use App\Models\HeatingReport;
use App\Models\Riser;
use App\Models\RiserCheck;
use App\Models\RiserDispute;
use App\Models\RiserLink;
use App\Models\RiserStatus;
use App\Models\Section;
use App\Models\SurveyRound;
use App\Tenancy\CurrentBuilding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes the riser_status + column_status projections of the CURRENT building (MVP-SPEC B.2 rule 7) for the
 * rolling window (`rolling`: active reports with valid_until in the future) and for survey rounds (`round:{id}`).
 * Residents read only these projections (counts), never heating_reports.
 *
 * Heating map risers = kind `heating` (radiators) and `hot_water_circulation` (towel dryers); column_status is
 * derived from the radiator risers whose links cover that position.
 */
final class RiserStatusProjector
{
    /** Riser kinds shown on the heating map. */
    public const KINDS = [RiserKind::Heating, RiserKind::HotWaterCirculation];

    public function __construct(private readonly RiserAggregator $aggregator) {}

    /**
     * Recompute `rolling` plus the given rounds (default: open rounds and rounds that ended in the last 14 days).
     *
     * @param  list<int>|null  $roundIds
     */
    public function recompute(?array $roundIds = null): void
    {
        $building = CurrentBuilding::get();
        $structure = $this->structure();

        $rounds = SurveyRound::query()
            ->when($roundIds !== null, fn (Builder $q) => $q->whereIn('id', $roundIds))
            ->when($roundIds === null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('status', SurveyRoundStatus::Open->value)
                ->orWhere(fn (Builder $c) => $c->where('status', SurveyRoundStatus::Closed->value)->where('ends_at', '>', now()->subDays(14)))))
            ->get();

        $keys = ['rolling' => HeatingReport::query()->valid()];
        foreach ($rounds as $round) {
            $keys[$round->roundKey()] = HeatingReport::query()->active()->where('survey_round_id', $round->id);
        }

        $confirmMin = $building->settings->confirmMinApartments;

        DB::transaction(function () use ($keys, $structure, $confirmMin, $building): void {
            foreach ($keys as $roundKey => $query) {
                $reports = $query->get(['apartment_id', 'room_id', 'status', 'reported_at']);
                $this->write((int) $building->id, $roundKey, $structure, $reports, $confirmMin);
            }
        });
    }

    /**
     * Live aggregation for a set of report rows (used by the manager report for a period). Returns aggregates
     * keyed by riser id plus the riser structure.
     *
     * @param  Collection<int, HeatingReport>  $reports
     * @param  list<int>|null  $sectionIds
     * @return array<int, RiserAggregate>
     */
    public function aggregateReports(Collection $reports, ?array $sectionIds = null): array
    {
        $structure = $this->structure();
        $confirmMin = CurrentBuilding::get()->settings->confirmMinApartments;
        $out = [];

        foreach ($this->perRiser($structure, $reports) as $riserId => $rows) {
            $riser = $structure['risers'][$riserId];
            if ($sectionIds !== null && ! in_array($riser['section_id'], $sectionIds, true)) {
                continue;
            }
            $out[$riserId] = $this->aggregator->aggregate(
                $rows,
                $riser['coverage'],
                $structure['floors'][$riser['section_id']] ?? 9,
                $confirmMin,
                isset($structure['disputed'][$riserId]),
                $structure['checks'][$riserId] ?? null,
            );
        }

        return $out;
    }

    /**
     * Structure of the heating map: risers, links, apartment floors/positions, section floors, open disputes and
     * latest checks.
     *
     * @return array{
     *     risers: array<int, array{id: int, section_id: int, code: string, kind: string, position: ?int, positions: list<int>, coverage: int, apartments: list<int>}>,
     *     links: array<string, list<int>>,
     *     apartments: array<int, array{floor: int, section_id: int, position: int}>,
     *     floors: array<int, int>,
     *     disputed: array<int, true>,
     *     checks: array<int, array{result: string, checked_at: string}>
     * }
     */
    public function structure(): array
    {
        $risers = Riser::query()->whereIn('kind', array_map(static fn (RiserKind $k): string => $k->value, self::KINDS))
            ->orderBy('code')->get(['id', 'section_id', 'code', 'kind', 'position']);
        $links = RiserLink::query()->whereIn('riser_id', $risers->pluck('id'))->get(['riser_id', 'apartment_id', 'room_id']);
        $apartments = Apartment::query()->whereIn('id', $links->pluck('apartment_id')->unique())->get(['id', 'floor', 'section_id', 'position'])->keyBy('id');

        $linkIndex = [];
        $byRiser = [];
        foreach ($links as $l) {
            $linkIndex[$l->apartment_id.':'.($l->room_id ?? '')][] = (int) $l->riser_id;
            $byRiser[(int) $l->riser_id][(int) $l->apartment_id] = true;
        }

        $out = [];
        foreach ($risers as $r) {
            $apts = array_map('intval', array_keys($byRiser[$r->id] ?? []));
            $positions = $r->position !== null
                ? [(int) $r->position]
                : array_values(array_unique(array_map(static fn (int $a): int => (int) $apartments->get($a)?->position, $apts)));
            sort($positions);
            $out[(int) $r->id] = [
                'id' => (int) $r->id,
                'section_id' => (int) $r->section_id,
                'code' => $r->code,
                'kind' => $r->kind->value,
                'position' => $r->position,
                'positions' => array_values(array_filter($positions)),
                'coverage' => count($apts),
                'apartments' => $apts,
            ];
        }

        $checks = [];
        foreach (RiserCheck::query()->whereIn('riser_id', array_keys($out))->orderBy('checked_at')->get(['riser_id', 'result', 'checked_at']) as $c) {
            $checks[(int) $c->riser_id] = ['result' => $c->result->value, 'checked_at' => $c->checked_at->toIso8601String()];
        }

        return [
            'risers' => $out,
            'links' => $linkIndex,
            'apartments' => $apartments->map(static fn (Apartment $a): array => [
                'floor' => $a->floor,
                'section_id' => $a->section_id,
                'position' => $a->position,
            ])->all(),
            'floors' => Section::query()->pluck('floors', 'id')->map(static fn ($v): int => (int) $v)->all(),
            'disputed' => array_fill_keys(RiserDispute::query()->open()->whereIn('riser_id', array_keys($out))->pluck('riser_id')->map(static fn ($v): int => (int) $v)->unique()->values()->all(), true),
            'checks' => $checks,
        ];
    }

    /**
     * Group report rows by riser (a report counts for every riser linked to its apartment + room, or to the whole
     * apartment).
     *
     * @param  array{risers: array<int, array<string, mixed>>, links: array<string, list<int>>, apartments: array<int, array{floor: int, section_id: int, position: int}>}  $structure
     * @param  Collection<int, HeatingReport>  $reports
     * @return array<int, list<array{apartment_id: int, floor: int, status: string, reported_at: string}>>
     */
    private function perRiser(array $structure, Collection $reports): array
    {
        $grouped = array_fill_keys(array_keys($structure['risers']), []);

        foreach ($reports as $report) {
            $apartment = $structure['apartments'][(int) $report->apartment_id] ?? null;
            if ($apartment === null) {
                continue;
            }
            $riserIds = [
                ...($structure['links'][$report->apartment_id.':'.$report->room_id] ?? []),
                ...($structure['links'][$report->apartment_id.':'] ?? []),
            ];
            foreach (array_unique($riserIds) as $riserId) {
                $grouped[$riserId][] = [
                    'apartment_id' => (int) $report->apartment_id,
                    'floor' => $apartment['floor'],
                    'status' => $report->status->value,
                    'reported_at' => $report->reported_at->toIso8601String(),
                ];
            }
        }

        return $grouped;
    }

    /**
     * @param  array{risers: array<int, array{id: int, section_id: int, code: string, kind: string, position: ?int, positions: list<int>, coverage: int, apartments: list<int>}>, links: array<string, list<int>>, apartments: array<int, array{floor: int, section_id: int, position: int}>, floors: array<int, int>, disputed: array<int, true>, checks: array<int, array{result: string, checked_at: string}>}  $structure
     * @param  Collection<int, HeatingReport>  $reports
     */
    private function write(int $buildingId, string $roundKey, array $structure, Collection $reports, int $confirmMin): void
    {
        $now = now();
        $riserRows = [];
        $aggregates = [];

        foreach ($this->perRiser($structure, $reports) as $riserId => $rows) {
            $riser = $structure['risers'][$riserId];
            $agg = $this->aggregator->aggregate(
                $rows,
                $riser['coverage'],
                $structure['floors'][$riser['section_id']] ?? 9,
                $confirmMin,
                isset($structure['disputed'][$riserId]),
                $structure['checks'][$riserId] ?? null,
            );
            $aggregates[$riserId] = $agg;

            $riserRows[] = [
                'building_id' => $buildingId,
                'section_id' => $riser['section_id'],
                'riser_id' => $riserId,
                'round_key' => $roundKey,
                'status' => $agg->status->value,
                'confirmed' => $agg->confirmed,
                'distinct_apartments' => $agg->distinctApartments,
                'floors_covered' => $agg->floorsCovered,
                'status_counts' => json_encode($agg->statusCounts, JSON_THROW_ON_ERROR),
                'coverage_total' => $agg->coverageTotal,
                'conflict' => $agg->conflict,
                'checked_state' => $agg->checkedState,
                'disputed' => $agg->disputed,
                'whole_riser_under' => $agg->wholeRiserUnder,
                'floors_reported' => '{'.implode(',', $agg->floorsReported).'}',
                'top_floor_only' => $agg->topFloorOnly,
                'updated_at' => $now,
            ];
        }

        RiserStatus::query()->where('round_key', $roundKey)->delete();
        foreach (array_chunk($riserRows, 200) as $chunk) {
            RiserStatus::query()->insert($chunk);
        }

        ColumnStatus::query()->where('round_key', $roundKey)->delete();
        $columnRows = $this->columns($buildingId, $roundKey, $structure, $aggregates, $now);
        foreach (array_chunk($columnRows, 200) as $chunk) {
            ColumnStatus::query()->insert($chunk);
        }
    }

    /**
     * @param  array{risers: array<int, array{id: int, section_id: int, code: string, kind: string, position: ?int, positions: list<int>, coverage: int, apartments: list<int>}>, apartments: array<int, array{floor: int, section_id: int, position: int}>}  $structure
     * @param  array<int, RiserAggregate>  $aggregates
     * @return list<array<string, mixed>>
     */
    private function columns(int $buildingId, string $roundKey, array $structure, array $aggregates, mixed $now): array
    {
        $columns = [];
        foreach ($structure['risers'] as $riserId => $riser) {
            if ($riser['kind'] !== RiserKind::Heating->value) {
                continue;
            }
            foreach ($riser['positions'] as $position) {
                $columns[$riser['section_id'].':'.$position][] = $riserId;
            }
        }

        $rows = [];
        foreach ($columns as $key => $riserIds) {
            [$sectionId, $position] = array_map('intval', explode(':', $key));
            $confirmed = [];
            $unconfirmed = [];
            $apartments = [];
            $floors = [];
            $linked = [];
            $disputed = false;
            $checked = false;
            $conflict = false;

            foreach ($riserIds as $riserId) {
                $agg = $aggregates[$riserId];
                foreach ($structure['risers'][$riserId]['apartments'] as $a) {
                    $linked[$a] = true;
                }
                foreach ($agg->apartmentIds as $a) {
                    $apartments[$a] = true;
                    $floors[$structure['apartments'][$a]['floor'] ?? 0] = true;
                }
                $disputed = $disputed || $agg->disputed;
                $checked = $checked || $agg->checkedState !== null;
                $conflict = $conflict || $agg->conflict;
                if ($agg->distinctApartments === 0) {
                    continue;
                }
                if ($agg->confirmed) {
                    $confirmed[] = $agg->status;
                } else {
                    $unconfirmed[] = $agg->status;
                }
            }

            $rows[] = [
                'building_id' => $buildingId,
                'section_id' => $sectionId,
                'position' => $position,
                'round_key' => $roundKey,
                'worst_confirmed_status' => AggregateStatus::worst($confirmed)?->value,
                'unconfirmed_status' => AggregateStatus::worst($unconfirmed)?->value,
                'has_unconfirmed' => $unconfirmed !== [],
                'distinct_apartments' => count($apartments),
                'floors_covered' => count(array_filter(array_keys($floors))),
                'coverage_total' => count($linked),
                'disputed' => $disputed,
                'checked' => $checked,
                'conflict' => $conflict,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }
}
