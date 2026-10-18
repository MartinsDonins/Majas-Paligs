<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksParkingGate;

/**
 * ParkingAnalyticsPolicy (MVP-SPEC C.4, gate names `parking-analytics` / `parking-analytics-apartments`):
 * aggregate tiles — parking_officer / building_admin at L1; per-apartment lists only after the house adopted parking
 * rules (`parking_rules_adopted_at`). Residents see per-stairwell aggregates only (CampaignProgress).
 */
final class ParkingAnalyticsPolicy
{
    use ChecksParkingGate;

    public function view(User $user): bool
    {
        return $this->parkingLevel() >= 1 && ! $user->isTombstone() && $this->isParkingStaff($user);
    }

    public function viewPerApartment(User $user): bool
    {
        $b = $this->building();

        return $this->view($user) && $b !== null && $b->settings->parkingRulesAdoptedAt !== null;
    }
}
