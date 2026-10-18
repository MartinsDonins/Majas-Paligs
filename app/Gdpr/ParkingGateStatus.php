<?php

declare(strict_types=1);

namespace App\Gdpr;

/**
 * Result of ParkingGate::status(): level 0 (no personal data), 1 (processing — officer/admin prepare data) or
 * 2 (resident-facing parking), the missing conditions per level and whether the module is switched on.
 */
final readonly class ParkingGateStatus
{
    /**
     * @param  list<string>  $missingL1  condition keys (ParkingGate::L1_CONDITIONS)
     * @param  list<string>  $missingL2  condition keys (ParkingGate::L2_CONDITIONS)
     */
    public function __construct(
        public int $level,
        public array $missingL1,
        public array $missingL2,
        public bool $moduleOn,
    ) {}

    public function l1(): bool
    {
        return $this->level >= 1;
    }

    public function l2(): bool
    {
        return $this->level >= 2;
    }

    /**
     * Resident parking (lookup, sightings, declarations, Manas caurlaides, nav item, Home tiles) is live.
     */
    public function residentEnabled(): bool
    {
        return $this->l2() && $this->moduleOn;
    }

    /**
     * @return list<string>
     */
    public function missingLabels(int $level): array
    {
        $keys = $level === 1 ? $this->missingL1 : $this->missingL2;

        return array_map(static fn (string $k): string => __('gate.parking.missing.'.$k), $keys);
    }
}
