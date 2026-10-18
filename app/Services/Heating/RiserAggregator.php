<?php

declare(strict_types=1);

namespace App\Services\Heating;

use App\Enums\AggregateStatus;
use App\Enums\HeatingStatus;
use Carbon\CarbonImmutable;

/**
 * Pure aggregation rules for one riser (MVP-SPEC B.2). No database access — the projector feeds it.
 *
 * 1. Input: report rows for rooms linked to the riser; only the LATEST row per apartment counts.
 * 2. distinct_apartments = number of apartments; confirmed = distinct ≥ confirm_min_apartments (2).
 * 3. Status = majority among apartments with cold + lukewarm grouped as "under" (then the more frequent of the
 *    two, ties → cold); top share < 60 % → `mixed` + conflict.
 * 4. top_floor_only (STAFF ONLY) = every "under" report comes from the top two floors.
 * 4a. floors_covered = distinct floors with a report; status_counts = apartments per state.
 * 4b. whole_riser_under = confirmed AND distinct ≥ 3 AND ≥ 80 % under AND reports on both the lower (1–4) and the
 *     upper (6–9) floors AND NOT top_floor_only.
 * 5. disputed is passed through (an open dispute freezes the column).
 * 6. checked_state = the latest riser check, if it is newer than the newest report.
 */
final class RiserAggregator
{
    public const MIXED_THRESHOLD = 0.6;

    public const WHOLE_RISER_SHARE = 0.8;

    /**
     * @param  iterable<array{apartment_id: int, floor: int, status: HeatingStatus|string, reported_at: CarbonImmutable|string}>  $reports
     * @param  array{result: string, checked_at: CarbonImmutable|string}|null  $latestCheck
     */
    public function aggregate(
        iterable $reports,
        int $coverageTotal,
        int $floorsTotal,
        int $confirmMinApartments = 2,
        bool $disputed = false,
        ?array $latestCheck = null,
    ): RiserAggregate {
        // 1. Latest report per apartment.
        $latest = [];
        foreach ($reports as $r) {
            $at = $r['reported_at'] instanceof CarbonImmutable ? $r['reported_at'] : CarbonImmutable::parse($r['reported_at']);
            $id = (int) $r['apartment_id'];
            if (! isset($latest[$id]) || $at->greaterThan($latest[$id]['at'])) {
                $latest[$id] = [
                    'floor' => (int) $r['floor'],
                    'status' => $r['status'] instanceof HeatingStatus ? $r['status'] : HeatingStatus::from((string) $r['status']),
                    'at' => $at,
                ];
            }
        }

        $counts = ['cold' => 0, 'lukewarm' => 0, 'warm' => 0, 'overheated' => 0];
        $floors = [];
        $underFloors = [];
        $newest = null;
        foreach ($latest as $row) {
            $counts[$row['status']->value]++;
            $floors[$row['floor']] = true;
            if ($row['status']->isUnder()) {
                $underFloors[$row['floor']] = true;
            }
            if ($newest === null || $row['at']->greaterThan($newest)) {
                $newest = $row['at'];
            }
        }

        // 2.
        $distinct = count($latest);
        $confirmed = $distinct >= max(2, $confirmMinApartments);

        // 3.
        [$status, $conflict] = $this->majority($counts, $distinct);

        // 4. Staff-only flag: every "under" report from the top two floors.
        $topFrom = max(2, $floorsTotal - 1);
        $topFloorOnly = $underFloors !== [] && min(array_keys($underFloors)) >= $topFrom && $floorsTotal >= 4;

        // 4a.
        $floorsReported = array_map('intval', array_keys($floors));
        sort($floorsReported);

        // 4b.
        $band = max(1, intdiv($floorsTotal * 4, 9));
        $hasLower = $floorsReported !== [] && min($floorsReported) <= $band;
        $hasUpper = $floorsReported !== [] && max($floorsReported) >= $floorsTotal - $band + 1;
        $under = $counts['cold'] + $counts['lukewarm'];
        $wholeRiserUnder = $confirmed
            && $distinct >= 3
            && $under / max(1, $distinct) >= self::WHOLE_RISER_SHARE
            && $hasLower && $hasUpper
            && ! $topFloorOnly;

        // 6.
        $checkedState = null;
        if ($latestCheck !== null) {
            $checkedAt = $latestCheck['checked_at'] instanceof CarbonImmutable ? $latestCheck['checked_at'] : CarbonImmutable::parse($latestCheck['checked_at']);
            if ($newest === null || $checkedAt->greaterThanOrEqualTo($newest)) {
                $checkedState = (string) $latestCheck['result'];
            }
        }

        $apartmentIds = array_map('intval', array_keys($latest));
        sort($apartmentIds);

        return new RiserAggregate(
            status: $status,
            confirmed: $confirmed,
            distinctApartments: $distinct,
            floorsCovered: count($floorsReported),
            statusCounts: $counts,
            coverageTotal: $coverageTotal,
            conflict: $conflict,
            checkedState: $checkedState,
            disputed: $disputed,
            wholeRiserUnder: $wholeRiserUnder,
            floorsReported: $floorsReported,
            topFloorOnly: $topFloorOnly,
            apartmentIds: $apartmentIds,
        );
    }

    /**
     * @param  array{cold: int, lukewarm: int, warm: int, overheated: int}  $counts
     * @return array{0: AggregateStatus, 1: bool}
     */
    private function majority(array $counts, int $distinct): array
    {
        if ($distinct === 0) {
            return [AggregateStatus::None, false];
        }

        $groups = [
            'under' => $counts['cold'] + $counts['lukewarm'],
            'overheated' => $counts['overheated'],
            'warm' => $counts['warm'],
        ];
        arsort($groups);
        $top = (string) array_key_first($groups);
        $topCount = $groups[$top];

        if ($topCount / $distinct < self::MIXED_THRESHOLD) {
            return [AggregateStatus::Mixed, true];
        }

        if ($top === 'under') {
            return [$counts['cold'] >= $counts['lukewarm'] ? AggregateStatus::Cold : AggregateStatus::Lukewarm, false];
        }

        return [AggregateStatus::from($top), false];
    }
}
