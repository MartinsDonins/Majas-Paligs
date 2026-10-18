<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Casts\Bytea;
use App\Models\PlateSightingStat;
use App\Models\Sighting;
use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\DB;

/**
 * plate_sighting_stats projection (PARK-003): per plate bidx — sightings and distinct reporting apartments in 30
 * days, distinct days seen in 60 days, last seen. `is_repeat` only with ≥ 2 independent reporting apartments in
 * 30 days; it decays automatically (rows without sightings for 30 days lose the flag, after 60 days the row goes).
 * Officer/admin only — never part of a reporter payload.
 */
final class SightingStats
{
    public const REPEAT_MIN_APARTMENTS = 2;

    public function refresh(string $bidx): void
    {
        $buildingId = CurrentBuilding::id();
        $row = DB::selectOne(<<<'SQL'
            SELECT count(*) FILTER (WHERE seen_at > now() - interval '30 days') AS s30,
                   count(DISTINCT reporter_apartment_id) FILTER (WHERE seen_at > now() - interval '30 days') AS a30,
                   count(DISTINCT (seen_at AT TIME ZONE 'Europe/Riga')::date) FILTER (WHERE seen_at > now() - interval '60 days') AS d60,
                   max(seen_at) AS last_seen
              FROM sightings
             WHERE building_id = ? AND plate_bidx = ? AND status <> 'dismissed'
            SQL, [$buildingId, Bytea::literal($bidx)]);

        if ($row === null || (int) $row->d60 === 0) {
            PlateSightingStat::query()->where('plate_bidx', Bytea::literal($bidx))->delete();

            return;
        }

        DB::table('plate_sighting_stats')->upsert([[
            'building_id' => $buildingId,
            'plate_bidx' => Bytea::literal($bidx),
            'sightings_30d' => (int) $row->s30,
            'distinct_reporter_apartments_30d' => (int) $row->a30,
            'days_seen_60d' => (int) $row->d60,
            'last_seen_at' => $row->last_seen,
            'is_repeat' => (int) $row->a30 >= self::REPEAT_MIN_APARTMENTS,
            'updated_at' => now(),
        ]], ['building_id', 'plate_bidx'], ['sightings_30d', 'distinct_reporter_apartments_30d', 'days_seen_60d', 'last_seen_at', 'is_repeat', 'updated_at']);
    }

    /**
     * Rebuild the projection of the current building (hourly decay, after a re-key).
     */
    public function rebuild(): int
    {
        PlateSightingStat::query()->delete();

        $bidxs = Sighting::query()->where('seen_at', '>', now()->subDays(60))
            ->distinct()->pluck('plate_bidx');

        foreach ($bidxs as $bidx) {
            $this->refresh((string) $bidx);
        }

        return $bidxs->count();
    }

    public function isRepeat(string $bidx): bool
    {
        return PlateSightingStat::query()->where('plate_bidx', Bytea::literal($bidx))->where('is_repeat', true)->exists();
    }
}
