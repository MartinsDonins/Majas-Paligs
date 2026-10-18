<?php

declare(strict_types=1);

namespace App\Services\Heating;

use App\Enums\AggregateStatus;

/**
 * Result of RiserAggregator for one riser (MVP-SPEC B.2). `floorsReported` and `topFloorOnly` are RESTRICTED:
 * they identify apartments (riser + floor = apartment) and reach staff views only.
 */
final readonly class RiserAggregate
{
    /**
     * @param  array{cold: int, lukewarm: int, warm: int, overheated: int}  $statusCounts  apartments per state
     * @param  list<int>  $floorsReported  RESTRICTED
     * @param  list<int>  $apartmentIds  RESTRICTED (never stored in a projection)
     */
    public function __construct(
        public AggregateStatus $status,
        public bool $confirmed,
        public int $distinctApartments,
        public int $floorsCovered,
        public array $statusCounts,
        public int $coverageTotal,
        public bool $conflict,
        public ?string $checkedState,
        public bool $disputed,
        public bool $wholeRiserUnder,
        public array $floorsReported,
        public bool $topFloorOnly,
        public array $apartmentIds = [],
    ) {}

    public function underCount(): int
    {
        return $this->statusCounts['cold'] + $this->statusCounts['lukewarm'];
    }
}
