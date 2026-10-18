<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Registry completeness (MVP-SPEC C.5): floor(100 × covered / total) where total = the building's apartments
 * with archived_at IS NULL and covered = those with ≥ 1 ACTIVE vehicle or a "mums nav auto" declaration made
 * within the last 12 months. Pending vehicles do not count until officer-verified. Per-stairwell rings use the
 * same formula per section. Cached 10 minutes per building; registry writes call forget().
 */
final class Completeness
{
    public const CACHE_SECONDS = 600;

    /**
     * @return array{percent: int, covered: int, total: int}
     */
    public function forBuilding(): array
    {
        $rows = $this->perSection();
        $covered = array_sum(array_column($rows, 'covered'));
        $total = array_sum(array_column($rows, 'total'));

        return ['percent' => self::percent($covered, $total), 'covered' => $covered, 'total' => $total];
    }

    /**
     * @return list<array{section: int, percent: int, covered: int, total: int}>
     */
    public function perSection(): array
    {
        $buildingId = CurrentBuilding::id();

        /** @var list<array{section: int, percent: int, covered: int, total: int}> */
        return Cache::remember(self::key($buildingId), self::CACHE_SECONDS, static function () use ($buildingId): array {
            $rows = DB::select(<<<'SQL'
                SELECT s.number AS section,
                       count(a.id) AS total,
                       count(a.id) FILTER (WHERE
                           EXISTS (SELECT 1 FROM vehicles v
                                    WHERE v.building_id = a.building_id AND v.apartment_id = a.id AND v.status = 'active')
                        OR EXISTS (SELECT 1 FROM apartment_parking_declarations d
                                    WHERE d.building_id = a.building_id AND d.apartment_id = a.id AND d.has_no_vehicle
                                      AND d.declared_at > now() - interval '12 months')
                       ) AS covered
                  FROM sections s
                  LEFT JOIN apartments a ON a.section_id = s.id AND a.building_id = s.building_id AND a.archived_at IS NULL
                 WHERE s.building_id = ?
                 GROUP BY s.number
                 ORDER BY s.number
                SQL, [$buildingId]);

            return array_map(static fn (object $r): array => [
                'section' => (int) $r->section,
                'percent' => self::percent((int) $r->covered, (int) $r->total),
                'covered' => (int) $r->covered,
                'total' => (int) $r->total,
            ], $rows);
        });
    }

    public static function forget(?int $buildingId = null): void
    {
        Cache::forget(self::key($buildingId ?? CurrentBuilding::id()));
    }

    public static function percent(int $covered, int $total): int
    {
        return $total > 0 ? intdiv(100 * $covered, $total) : 0;
    }

    private static function key(int $buildingId): string
    {
        return 'parking:completeness:'.$buildingId;
    }
}
