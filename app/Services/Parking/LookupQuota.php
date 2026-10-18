<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Casts\Bytea;
use App\Enums\BuildingRoleType;
use App\Enums\LookupReason;
use App\Models\PlateLookup;
use App\Models\User;
use App\Notifications\Alerts;
use App\Tenancy\CurrentBuilding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The shared lookup quota (MVP-SPEC C.5): lookups, declarations and sightings each write one plate_lookups row
 * and consume one unit — 5/h and 20/day per user, 200/day per building (settings.lookup_quota). The log IS the
 * counter, so no lookup can happen unlogged. Writes are serialised per user and per building with advisory locks.
 *
 * Alerts carry counts only (A.6): building quota at 50 % and on hit → parking_officer + elders; the same plate
 * looked up ≥ 3 times a day by one user → parking_officer (never the plate, bidx or user).
 */
final class LookupQuota
{
    public const REPEAT_ALERT_AT = 3;

    public function __construct(private readonly Alerts $alerts) {}

    /**
     * Record a row if the quota allows it. Returns null when recorded, otherwise the seconds until the next unit.
     * `$result` is the yes/no the user was SHOWN (resident lookups); declarations, sightings and disputes pass null
     * because their answer is deliberately identical (no registration oracle — MVP-SPEC C.5, C.7 AC 16).
     */
    public function record(User $user, string $bidx, LookupReason $reason, ?bool $result): ?int
    {
        $building = CurrentBuilding::get();
        $quota = $building->settings->lookupQuota;

        return DB::transaction(function () use ($user, $bidx, $reason, $result, $building, $quota): ?int {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['lookup-user:'.$user->id]);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['lookup-building:'.$building->id]);

            $now = now();
            $mine = PlateLookup::query()->where('user_id', $user->id);

            $hour = (clone $mine)->where('created_at', '>', $now->subHour());
            if ($hour->count() >= $quota['user_hour']) {
                return $this->secondsUntilFree($hour, 3600);
            }

            $day = (clone $mine)->where('created_at', '>', $now->subDay());
            if ($day->count() >= $quota['user_day']) {
                return $this->secondsUntilFree($day, 86400);
            }

            $buildingDay = PlateLookup::query()->where('created_at', '>', $now->subDay());
            if ($buildingDay->count() >= $quota['building_day']) {
                return $this->secondsUntilFree($buildingDay, 86400);
            }

            PlateLookup::query()->create([
                'user_id' => $user->id,
                'plate_bidx' => $bidx,
                'result' => $result,
                'reason_code' => $reason,
            ]);

            $this->alertIfNeeded($user, $bidx, $buildingDay->count(), $quota['building_day']);

            return null;
        });
    }

    /**
     * Seconds until the oldest row inside the window ages out (friendly countdown).
     *
     * @param  Builder<PlateLookup>  $window
     */
    private function secondsUntilFree($window, int $windowSeconds): int
    {
        $oldest = (clone $window)->min('created_at');
        if ($oldest === null) {
            return 60;
        }

        $free = CarbonImmutable::parse((string) $oldest)->addSeconds($windowSeconds);

        return max(1, (int) ceil(now()->diffInSeconds($free, false)));
    }

    private function alertIfNeeded(User $user, string $bidx, int $buildingCount, int $buildingLimit): void
    {
        $building = CurrentBuilding::get();
        $half = (int) ceil($buildingLimit / 2);

        if ($buildingLimit > 0 && ($buildingCount === $half || $buildingCount === $buildingLimit)) {
            $this->alerts->building($building, 'lookup-building', 'day', $buildingCount, $buildingLimit, [
                BuildingRoleType::ParkingOfficer->value, BuildingRoleType::SectionElder->value,
            ]);
        }

        $samePlate = PlateLookup::query()
            ->where('user_id', $user->id)
            ->where('plate_bidx', Bytea::literal($bidx))
            ->where('created_at', '>', now()->subDay())
            ->count();

        if ($samePlate === self::REPEAT_ALERT_AT) {
            $this->alerts->building($building, 'lookup-repeat', 'day', $samePlate, self::REPEAT_ALERT_AT, [
                BuildingRoleType::ParkingOfficer->value,
            ]);
        }
    }
}
