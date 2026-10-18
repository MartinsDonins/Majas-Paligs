<?php

declare(strict_types=1);

namespace App\Services\Heating\Views;

/**
 * Staff riser view: the resident aggregate PLUS the restricted fields (floors_reported, top_floor_only,
 * whole_riser_under). Constructed only by HeatingGridQuery::staffSection(), which first requires
 * HeatingReportPolicy::viewApartments($user, $section) — building_admin any stairwell, section_elder only their own.
 */
final readonly class StaffRiserView
{
    /**
     * @param  list<int>  $floorsReported
     */
    public function __construct(
        public ResidentRiserView $aggregate,
        public array $floorsReported,
        public bool $topFloorOnly,
        public bool $wholeRiserUnder,
    ) {}
}
