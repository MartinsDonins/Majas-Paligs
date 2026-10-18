<?php

declare(strict_types=1);

use App\Enums\AggregateStatus;
use App\Services\Heating\RiserAggregator;
use Carbon\CarbonImmutable;

/*
 * MVP-SPEC B.2 aggregation rules (pure, no database).
 */
function aggRows(array $spec): array
{
    $base = CarbonImmutable::parse('2026-11-10 12:00:00');
    $out = [];
    foreach ($spec as $i => [$apartment, $floor, $status]) {
        $out[] = ['apartment_id' => $apartment, 'floor' => $floor, 'status' => $status, 'reported_at' => $base->addMinutes($i)];
    }

    return $out;
}

it('aggregates the dataset', function (array $spec, string $status, bool $confirmed, int $distinct, bool $conflict): void {
    $agg = (new RiserAggregator)->aggregate(aggRows($spec), coverageTotal: 9, floorsTotal: 9);

    expect($agg->status->value)->toBe($status)
        ->and($agg->confirmed)->toBe($confirmed)
        ->and($agg->distinctApartments)->toBe($distinct)
        ->and($agg->conflict)->toBe($conflict);
})->with([
    'no reports' => [[], 'none', false, 0, false],
    'one apartment → unconfirmed' => [[[1, 3, 'cold']], 'cold', false, 1, false],
    'two apartments → confirmed' => [[[1, 3, 'cold'], [5, 4, 'cold']], 'cold', true, 2, false],
    'same apartment twice never confirms' => [[[1, 3, 'cold'], [1, 3, 'cold']], 'cold', false, 1, false],
    'cold + lukewarm grouped as under (tie → cold)' => [[[1, 1, 'cold'], [5, 2, 'lukewarm']], 'cold', true, 2, false],
    'lukewarm majority inside under' => [[[1, 1, 'lukewarm'], [5, 2, 'lukewarm'], [9, 3, 'cold']], 'lukewarm', true, 3, false],
    'mixed below 60 %' => [[[1, 1, 'cold'], [5, 2, 'warm']], 'mixed', true, 2, true],
    'warm majority ≥ 60 %' => [[[1, 1, 'warm'], [5, 2, 'warm'], [9, 3, 'cold']], 'warm', true, 3, false],
    'overheated' => [[[1, 1, 'overheated'], [5, 2, 'overheated']], 'overheated', true, 2, false],
]);

it('counts only the latest report per apartment', function (): void {
    $agg = (new RiserAggregator)->aggregate(aggRows([[1, 3, 'cold'], [1, 3, 'warm']]), 9, 9);

    expect($agg->status)->toBe(AggregateStatus::Warm)
        ->and($agg->statusCounts)->toBe(['cold' => 0, 'lukewarm' => 0, 'warm' => 1, 'overheated' => 0]);
});

it('computes floors_covered and status_counts as numbers', function (): void {
    $agg = (new RiserAggregator)->aggregate(aggRows([[1, 1, 'cold'], [5, 2, 'cold'], [9, 3, 'warm'], [13, 3, 'cold']]), 9, 9);

    expect($agg->floorsCovered)->toBe(3)
        ->and($agg->statusCounts)->toBe(['cold' => 3, 'lukewarm' => 0, 'warm' => 1, 'overheated' => 0])
        ->and($agg->floorsReported)->toBe([1, 2, 3]);
});

it('flags top_floor_only (staff) when every under report is from the two top floors', function (): void {
    $top = (new RiserAggregator)->aggregate(aggRows([[33, 9, 'cold'], [29, 8, 'cold'], [1, 1, 'warm']]), 9, 9);
    $mixed = (new RiserAggregator)->aggregate(aggRows([[33, 9, 'cold'], [1, 1, 'cold']]), 9, 9);

    expect($top->topFloorOnly)->toBeTrue()->and($mixed->topFloorOnly)->toBeFalse();
});

it('detects whole_riser_under only with ≥3 apartments, ≥80 % under and both lower and upper floors', function (): void {
    $agg = new RiserAggregator;

    $whole = $agg->aggregate(aggRows([[1, 1, 'cold'], [9, 3, 'cold'], [21, 6, 'lukewarm'], [33, 9, 'cold']]), 9, 9);
    $onlyLow = $agg->aggregate(aggRows([[1, 1, 'cold'], [5, 2, 'cold'], [9, 3, 'cold']]), 9, 9);
    $twoOnly = $agg->aggregate(aggRows([[1, 1, 'cold'], [33, 9, 'cold']]), 9, 9);
    $warmish = $agg->aggregate(aggRows([[1, 1, 'cold'], [9, 3, 'warm'], [21, 6, 'warm'], [33, 9, 'cold']]), 9, 9);
    $topOnly = $agg->aggregate(aggRows([[29, 8, 'cold'], [33, 9, 'cold'], [1, 1, 'warm']]), 9, 9);

    expect($whole->wholeRiserUnder)->toBeTrue()
        ->and($onlyLow->wholeRiserUnder)->toBeFalse()
        ->and($twoOnly->wholeRiserUnder)->toBeFalse()
        ->and($warmish->wholeRiserUnder)->toBeFalse()
        ->and($topOnly->wholeRiserUnder)->toBeFalse();
});

it('passes the dispute flag through', function (): void {
    expect((new RiserAggregator)->aggregate(aggRows([[1, 1, 'cold']]), 9, 9, disputed: true)->disputed)->toBeTrue();
});

it('sets checked_state only when the check is newer than the newest report', function (): void {
    $agg = new RiserAggregator;
    $reports = aggRows([[1, 1, 'cold'], [5, 2, 'cold']]);

    $newer = $agg->aggregate($reports, 9, 9, latestCheck: ['result' => 'air_vented', 'checked_at' => '2026-11-11 08:00:00']);
    $older = $agg->aggregate($reports, 9, 9, latestCheck: ['result' => 'air_vented', 'checked_at' => '2026-11-09 08:00:00']);

    expect($newer->checkedState)->toBe('air_vented')->and($older->checkedState)->toBeNull();
});

it('respects a higher confirm_min_apartments', function (): void {
    $agg = (new RiserAggregator)->aggregate(aggRows([[1, 1, 'cold'], [5, 2, 'cold']]), 9, 9, confirmMinApartments: 3);

    expect($agg->confirmed)->toBeFalse();
});
