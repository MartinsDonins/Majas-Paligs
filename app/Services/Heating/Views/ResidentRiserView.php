<?php

declare(strict_types=1);

namespace App\Services\Heating\Views;

use App\Enums\AggregateStatus;
use App\Enums\RiserKind;

/**
 * Resident-safe riser aggregate (MVP-SPEC B.1 "Restricted fields"): counts only. This type has NO floors_reported,
 * per-floor segments, top_floor_only or apartment ids — it is the only riser type resident components accept
 * (Heating\Grid in resident mode, riser list/summary, digest, Home mini map).
 */
final readonly class ResidentRiserView
{
    /**
     * @param  array{cold: int, lukewarm: int, warm: int, overheated: int}  $statusCounts
     */
    public function __construct(
        public int $riserId,
        public int $sectionId,
        public string $code,
        public RiserKind $kind,
        public ?int $position,
        public AggregateStatus $status,
        public bool $confirmed,
        public int $distinctApartments,
        public int $floorsCovered,
        public int $floorsTotal,
        public array $statusCounts,
        public int $coverageTotal,
        public bool $conflict,
        public ?string $checkedState,
        public bool $disputed,
    ) {}

    /**
     * Visible state of the riser: disputed → "Pārbaudāms"; no data; unconfirmed (one apartment); confirmed state.
     */
    public function displayState(): string
    {
        return match (true) {
            $this->disputed => 'disputed',
            $this->distinctApartments === 0 => 'none',
            ! $this->confirmed => 'unconfirmed',
            default => $this->status->value,
        };
    }

    public function isUnder(): bool
    {
        return $this->distinctApartments > 0 && $this->status->isUnder();
    }

    public function noDataFloors(): int
    {
        return max(0, $this->floorsTotal - $this->floorsCovered);
    }
}
