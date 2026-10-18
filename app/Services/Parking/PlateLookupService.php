<?php

declare(strict_types=1);

namespace App\Services\Parking;

use App\Enums\LookupReason;
use App\Models\User;
use App\Models\Vehicle;
use App\Tenancy\CurrentBuilding;

/**
 * "Vai šis auto ir mūsu?" (PARK-002, MVP-SPEC C.5): normalize → per-building blind index → `{registered: bool}`.
 * Exact match against ACTIVE vehicles only (pending declarations are not registered yet). Every lookup is
 * logged in plate_lookups and consumes the shared quota (LookupQuota); the caller authorises first
 * (PlateLookupPolicy: approved member, gate L2, module on).
 */
final class PlateLookupService
{
    public function __construct(
        private readonly PlateVault $vault,
        private readonly LookupQuota $quota,
    ) {}

    public function lookup(User $user, string $typed, LookupReason $reason): LookupResult
    {
        $plate = PlateNormalizer::normalize($typed);
        if ($plate === null) {
            return LookupResult::invalid();
        }

        $bidx = $this->vault->currentBidx(CurrentBuilding::get(), $plate);
        $registered = $this->isRegistered($bidx);

        $retry = $this->quota->record($user, $bidx, $reason, $registered);
        if ($retry !== null) {
            return LookupResult::limited($retry);
        }

        return new LookupResult(LookupResult::OK, registered: $registered);
    }

    public function isRegistered(string $bidx): bool
    {
        return Vehicle::query()->active()->withBidx($bidx)->exists();
    }
}
