<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Enums\PermitType;
use App\Models\Apartment;
use App\Models\Permit;
use App\Models\Vehicle;
use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\DB;

/**
 * Parking analytics (PARK-004, MVP-SPEC C.6) for parking_officer / building_admin — counts and distributions only.
 * Per-apartment lists (apartment numbers) only through perApartment(), which the caller may use only after the house
 * adopted parking rules (ParkingAnalyticsPolicy::viewPerApartment). Residents get per-stairwell completeness only.
 */
final class ParkingAnalytics
{
    public const SEEN_DAYS_DEFAULT = 5;

    public function __construct(private readonly Completeness $completeness) {}

    /**
     * @return array{
     *     permits_distribution: array<string, int>,
     *     apartments_two_main: int,
     *     active_vehicles: int,
     *     pending_vehicles: int,
     *     guest_permits: int,
     *     unregistered_seen: int,
     *     seen_days: int,
     *     completeness: array{percent: int, covered: int, total: int},
     *     sections: list<array{section: int, percent: int, covered: int, total: int}>
     * }
     */
    public function summary(int $seenDays = self::SEEN_DAYS_DEFAULT): array
    {
        $perApartment = $this->activePermitsPerApartment();
        $total = Apartment::query()->active()->count();
        $distribution = ['0' => max(0, $total - count($perApartment)), '1' => 0, '2' => 0, '3+' => 0];
        foreach ($perApartment as $count) {
            $distribution[$count >= 3 ? '3+' : (string) $count]++;
        }

        return [
            'permits_distribution' => $distribution,
            'apartments_two_main' => count($this->apartmentsWithTwoMain()),
            'active_vehicles' => Vehicle::query()->active()->count(),
            'pending_vehicles' => Vehicle::query()->where('status', 'pending')->count(),
            'guest_permits' => Permit::query()->active()->where('type', PermitType::Guest->value)->count(),
            'unregistered_seen' => $this->unregisteredSeen($seenDays),
            'seen_days' => $seenDays,
            'completeness' => $this->completeness->forBuilding(),
            'sections' => $this->completeness->perSection(),
        ];
    }

    /**
     * Apartment numbers with 2+ active main permits — ONLY after `parking_rules_adopted_at` (policy-checked by caller).
     *
     * @return list<int>
     */
    public function perApartment(): array
    {
        $ids = $this->apartmentsWithTwoMain();

        return Apartment::query()->whereIn('id', $ids)->orderBy('number')->pluck('number')->map(fn ($n): int => (int) $n)->all();
    }

    /**
     * Plates seen on ≥ $days distinct days in 60 days that are not ACTIVE in the registry (count only).
     */
    public function unregisteredSeen(int $days): int
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT count(*) AS c FROM plate_sighting_stats s
             WHERE s.building_id = ? AND s.days_seen_60d >= ?
               AND NOT EXISTS (SELECT 1 FROM vehicles v WHERE v.building_id = s.building_id AND v.plate_bidx = s.plate_bidx AND v.status = 'active')
            SQL, [CurrentBuilding::id(), max(1, $days)]);

        return (int) ($row->c ?? 0);
    }

    /**
     * @return array<int, int> apartment_id => active permits
     */
    private function activePermitsPerApartment(): array
    {
        return Permit::query()->active()
            ->selectRaw('apartment_id, count(*) AS c')
            ->groupBy('apartment_id')
            ->pluck('c', 'apartment_id')
            ->map(fn ($c): int => (int) $c)
            ->all();
    }

    /**
     * @return list<int> apartment ids
     */
    private function apartmentsWithTwoMain(): array
    {
        return Permit::query()->active()->where('type', PermitType::Main->value)
            ->selectRaw('apartment_id')
            ->groupBy('apartment_id')
            ->havingRaw('count(*) >= 2')
            ->pluck('apartment_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
