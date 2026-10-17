<?php

declare(strict_types=1);

namespace App\Support;

use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free per-building yearly counters (MVP-SPEC §0): MP-YYYY-NNNN (manager_report), PE-YYYY-NNNN
 * (parking_export). Must be called inside the caller's transaction: INSERT … ON CONFLICT DO NOTHING, then
 * SELECT … FOR UPDATE + increment — no gaps and no duplicates under concurrency.
 */
final class CounterService
{
    public const PREFIXES = ['manager_report' => 'MP', 'parking_export' => 'PE'];

    public function next(string $key, ?int $year = null): int
    {
        if (! array_key_exists($key, self::PREFIXES)) {
            throw new LogicException("Unknown counter [{$key}].");
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException('CounterService::next() must run inside the caller\'s transaction.');
        }

        $buildingId = CurrentBuilding::id();
        $year ??= (int) now('Europe/Riga')->format('Y');

        DB::statement(
            'INSERT INTO building_counters (building_id, key, year, value) VALUES (?, ?, ?, 0) ON CONFLICT DO NOTHING',
            [$buildingId, $key, $year],
        );

        $row = DB::selectOne(
            'SELECT value FROM building_counters WHERE building_id = ? AND key = ? AND year = ? FOR UPDATE',
            [$buildingId, $key, $year],
        );

        $value = (int) $row->value + 1;

        DB::update(
            'UPDATE building_counters SET value = ? WHERE building_id = ? AND key = ? AND year = ?',
            [$value, $buildingId, $key, $year],
        );

        return $value;
    }

    /**
     * Next formatted number, e.g. "MP-2026-0001".
     */
    public function nextNumber(string $key, ?int $year = null): string
    {
        $year ??= (int) now('Europe/Riga')->format('Y');

        return sprintf('%s-%d-%04d', self::PREFIXES[$key], $year, $this->next($key, $year));
    }
}
