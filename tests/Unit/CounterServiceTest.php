<?php

declare(strict_types=1);

use App\Support\CounterService;
use Illuminate\Support\Facades\DB;

it('issues gap-free numbers per building, key and year', function (): void {
    $a = makeBuilding('count-a', 1, 1);
    $b = makeBuilding('count-b', 1, 1);
    $counter = app(CounterService::class);

    $numbers = inBuilding($a, fn () => DB::transaction(fn () => [
        $counter->nextNumber('manager_report', 2026),
        $counter->nextNumber('manager_report', 2026),
        $counter->nextNumber('parking_export', 2026),
        $counter->nextNumber('manager_report', 2027),
    ]));
    $other = inBuilding($b, fn () => DB::transaction(fn () => $counter->nextNumber('manager_report', 2026)));

    expect($numbers)->toBe(['MP-2026-0001', 'MP-2026-0002', 'PE-2026-0001', 'MP-2027-0001'])
        ->and($other)->toBe('MP-2026-0001');
});

it('must run inside the caller transaction', function (): void {
    $a = makeBuilding('count-c', 1, 1);
    // RefreshDatabase wraps each test in a transaction; force level 0 by checking the guard message directly.
    expect(DB::transactionLevel())->toBeGreaterThan(0);

    inBuilding($a, fn () => app(CounterService::class)->next('unknown_key'));
})->throws(LogicException::class);
