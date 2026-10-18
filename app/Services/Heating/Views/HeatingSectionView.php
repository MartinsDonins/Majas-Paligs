<?php

declare(strict_types=1);

namespace App\Services\Heating\Views;

use App\Enums\AggregateStatus;
use App\Enums\HeatingStatus;

/**
 * Heating data of one stairwell for the grid. Resident mode: risers + columns only. Staff mode adds
 * `staffRisers` (restricted fields) and `apartments` (per-apartment worst state) — filled only by
 * HeatingGridQuery::staffSection() after the viewApartments policy passed.
 */
final readonly class HeatingSectionView
{
    /**
     * @param  list<ResidentRiserView>  $risers  worst first
     * @param  array<int, ColumnView>  $columns  position => column
     * @param  array<int, StaffRiserView>  $staffRisers  riser id => staff view (staff only)
     * @param  array<int, array{status: HeatingStatus, rooms: int, temperature: ?string, has_note: bool}>  $apartments  apartment id => state (staff only)
     */
    public function __construct(
        public int $sectionId,
        public int $sectionNumber,
        public int $floors,
        public array $risers,
        public array $columns,
        public bool $staff = false,
        public array $staffRisers = [],
        public array $apartments = [],
    ) {}

    public function column(int $position): ColumnView
    {
        return $this->columns[$position] ?? ColumnView::empty($this->sectionId, $position, $this->floors);
    }

    /**
     * @return list<ResidentRiserView>
     */
    public function risersAt(int $position): array
    {
        return array_values(array_filter($this->risers, static fn (ResidentRiserView $r): bool => $r->position === $position));
    }

    public function worst(): ?AggregateStatus
    {
        return AggregateStatus::worst(array_map(static fn (ColumnView $c): ?AggregateStatus => $c->worstConfirmed, $this->columns));
    }
}
