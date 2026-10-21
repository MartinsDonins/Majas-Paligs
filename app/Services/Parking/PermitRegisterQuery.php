<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Casts\PgArray;
use App\Models\Permit;
use App\Tenancy\CurrentBuilding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Permit register analytics (PARK-011, ROUND2-SPEC §6.2) — pure SQL over permits, vehicles, officer tasks and
 * guest-permit observations, never a decryption:
 *
 * - `multi_main` — the permit's apartment has ≥ 2 active main permits (filter "Dzīvokļi ar vairāk nekā 1
 *   caurlaidi" also takes apartments with > 2 active permits in total; both counts are shown);
 * - `guest_same_car` — a guest permit with ≥ 3 observations on ≥ 3 distinct days spanning ≥ 14 days and exactly one
 *   distinct plate blind index (thresholds: config majas.parking.guest_same_car);
 * - `main_without_vehicle` — an active main permit whose vehicle is not active, or an open permit_without_vehicle task;
 * - `vehicle_pending` — the apartment has a vehicle waiting for verification;
 * - `conflict` — the apartment has a pending vehicle in conflict, or the permit has an open vehicle_conflict task.
 *
 * flags() answers one table page in ONE query; the scopes power the filters.
 */
final class PermitRegisterQuery
{
    public const FLAGS = ['multi_main', 'guest_same_car', 'main_without_vehicle', 'vehicle_pending', 'conflict'];

    /**
     * @param  list<int>  $permitIds
     * @return array<int, array{flags: list<string>, main: int, total: int, observations: int}>
     */
    public function flags(array $permitIds): array
    {
        if ($permitIds === []) {
            return [];
        }

        $b = CurrentBuilding::id();
        [$minObs, $minDays, $span] = self::thresholds();

        $rows = DB::select(<<<'SQL'
            WITH page AS (
                SELECT p.id, p.apartment_id, p.type, p.status, p.vehicle_id
                  FROM permits p WHERE p.building_id = ? AND p.id = ANY(?::bigint[])
            ), apt AS (
                SELECT apartment_id, count(*) FILTER (WHERE type = 'main') AS main, count(*) AS total
                  FROM permits WHERE building_id = ? AND status = 'active'
                   AND apartment_id IN (SELECT apartment_id FROM page)
                 GROUP BY apartment_id
            ), obs AS (
                SELECT permit_id, count(*) AS c, count(DISTINCT observed_on) AS d,
                       max(observed_on) - min(observed_on) AS span, count(DISTINCT plate_bidx) AS plates
                  FROM permit_plate_observations WHERE building_id = ? AND permit_id IN (SELECT id FROM page)
                 GROUP BY permit_id
            )
            SELECT page.id, page.type, page.status,
                   coalesce(apt.main, 0) AS main, coalesce(apt.total, 0) AS total,
                   coalesce(obs.c, 0) AS obs_count, coalesce(obs.d, 0) AS obs_days, coalesce(obs.span, 0) AS obs_span,
                   coalesce(obs.plates, 0) AS obs_plates,
                   EXISTS (SELECT 1 FROM vehicles v WHERE v.building_id = ? AND v.apartment_id = page.apartment_id AND v.status = 'pending') AS vehicle_pending,
                   (EXISTS (SELECT 1 FROM vehicles v WHERE v.building_id = ? AND v.apartment_id = page.apartment_id AND v.status = 'pending' AND v.conflict)
                    OR EXISTS (SELECT 1 FROM parking_officer_tasks t WHERE t.building_id = ? AND t.permit_id = page.id AND t.kind = 'vehicle_conflict' AND t.status = 'open')) AS conflict,
                   ((page.type = 'main' AND page.status = 'active'
                     AND NOT EXISTS (SELECT 1 FROM vehicles v WHERE v.building_id = ? AND v.id = page.vehicle_id AND v.status = 'active'))
                    OR EXISTS (SELECT 1 FROM parking_officer_tasks t WHERE t.building_id = ? AND t.permit_id = page.id AND t.kind = 'permit_without_vehicle' AND t.status = 'open')) AS main_without_vehicle
              FROM page
              LEFT JOIN apt ON apt.apartment_id = page.apartment_id
              LEFT JOIN obs ON obs.permit_id = page.id
            SQL, [$b, PgArray::literal(array_map('intval', $permitIds), 'int'), $b, $b, $b, $b, $b, $b, $b]);

        $out = [];
        foreach ($rows as $row) {
            $active = $row->status === 'active';
            $flags = [];
            if ($active && (int) $row->main >= 2) {
                $flags[] = 'multi_main';
            }
            if ($row->type === 'guest' && (int) $row->obs_count >= $minObs && (int) $row->obs_days >= $minDays
                && (int) $row->obs_span >= $span && (int) $row->obs_plates === 1) {
                $flags[] = 'guest_same_car';
            }
            if ((bool) $row->main_without_vehicle) {
                $flags[] = 'main_without_vehicle';
            }
            if ($active && (bool) $row->vehicle_pending) {
                $flags[] = 'vehicle_pending';
            }
            if ($active && (bool) $row->conflict) {
                $flags[] = 'conflict';
            }

            $out[(int) $row->id] = ['flags' => $flags, 'main' => (int) $row->main, 'total' => (int) $row->total, 'observations' => (int) $row->obs_count];
        }

        return $out;
    }

    /**
     * Filter "Dzīvokļi ar vairāk nekā 1 caurlaidi": active permits of apartments with ≥ 2 active main permits or
     * > 2 active permits in total.
     *
     * @param  Builder<Permit>  $query
     * @return Builder<Permit>
     */
    public static function whereMultiPermit(Builder $query): Builder
    {
        return $query->where('permits.status', 'active')->whereIn('permits.apartment_id', static fn ($q) => $q
            ->from('permits as m')->select('m.apartment_id')
            ->where('m.building_id', CurrentBuilding::id())->where('m.status', 'active')
            ->groupBy('m.apartment_id')
            ->havingRaw("count(*) FILTER (WHERE m.type = 'main') >= 2 OR count(*) > 2"));
    }

    /**
     * Filter "Viesu caurlaide vienmēr vienam auto".
     *
     * @param  Builder<Permit>  $query
     * @return Builder<Permit>
     */
    public static function whereGuestSameCar(Builder $query): Builder
    {
        [$minObs, $minDays, $span] = self::thresholds();

        return $query->where('permits.type', 'guest')->whereIn('permits.id', static fn ($q) => $q
            ->from('permit_plate_observations as o')->select('o.permit_id')
            ->where('o.building_id', CurrentBuilding::id())
            ->groupBy('o.permit_id')
            ->havingRaw('count(*) >= ? AND count(DISTINCT o.observed_on) >= ? AND max(o.observed_on) - min(o.observed_on) >= ? AND count(DISTINCT o.plate_bidx) = 1', [$minObs, $minDays, $span]));
    }

    /**
     * @param  Builder<Permit>  $query
     * @return Builder<Permit>
     */
    public static function whereMainWithoutVehicle(Builder $query): Builder
    {
        $b = CurrentBuilding::id();

        return $query->where(static fn (Builder $q) => $q
            ->where(static fn (Builder $q) => $q->where('permits.type', 'main')->where('permits.status', 'active')
                ->whereNotExists(static fn ($s) => $s->from('vehicles as v')->selectRaw('1')
                    ->where('v.building_id', $b)->whereColumn('v.id', 'permits.vehicle_id')->where('v.status', 'active')))
            ->orWhereExists(static fn ($s) => $s->from('parking_officer_tasks as t')->selectRaw('1')
                ->where('t.building_id', $b)->whereColumn('t.permit_id', 'permits.id')
                ->where('t.kind', 'permit_without_vehicle')->where('t.status', 'open')));
    }

    /**
     * @param  Builder<Permit>  $query
     * @return Builder<Permit>
     */
    public static function whereVehiclePending(Builder $query): Builder
    {
        return $query->where('permits.status', 'active')->whereExists(static fn ($s) => $s->from('vehicles as v')->selectRaw('1')
            ->where('v.building_id', CurrentBuilding::id())->whereColumn('v.apartment_id', 'permits.apartment_id')->where('v.status', 'pending'));
    }

    /**
     * @param  Builder<Permit>  $query
     * @return Builder<Permit>
     */
    public static function whereConflict(Builder $query): Builder
    {
        $b = CurrentBuilding::id();

        return $query->where('permits.status', 'active')->where(static fn (Builder $q) => $q
            ->whereExists(static fn ($s) => $s->from('vehicles as v')->selectRaw('1')
                ->where('v.building_id', $b)->whereColumn('v.apartment_id', 'permits.apartment_id')->where('v.status', 'pending')->where('v.conflict', true))
            ->orWhereExists(static fn ($s) => $s->from('parking_officer_tasks as t')->selectRaw('1')
                ->where('t.building_id', $b)->whereColumn('t.permit_id', 'permits.id')
                ->where('t.kind', 'vehicle_conflict')->where('t.status', 'open')));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function thresholds(): array
    {
        $c = (array) config('majas.parking.guest_same_car', []);

        return [max(1, (int) ($c['min_observations'] ?? 3)), max(1, (int) ($c['min_days'] ?? 3)), max(0, (int) ($c['min_span_days'] ?? 14))];
    }
}
