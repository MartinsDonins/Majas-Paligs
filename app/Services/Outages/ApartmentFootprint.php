<?php

declare(strict_types=1);

namespace App\Services\Outages;

use App\Models\Apartment;
use App\Models\ApartmentCell;
use App\Models\Incident;
use App\Models\RiserLink;

/**
 * Where an apartment sits for outage coverage: section, floor, the grid positions of all its cells (a merged
 * apartment owns several) and every riser it is linked to.
 */
final readonly class ApartmentFootprint
{
    /**
     * @param  list<int>  $positions
     * @param  list<int>  $riserIds
     */
    public function __construct(
        public int $apartmentId,
        public int $sectionId,
        public int $floor,
        public int $primaryPosition,
        public array $positions,
        public array $riserIds,
    ) {}

    public static function of(Apartment $apartment): self
    {
        $positions = ApartmentCell::query()->where('apartment_id', $apartment->id)->pluck('position')
            ->map(static fn ($p): int => (int) $p)->unique()->sort()->values()->all();

        return new self(
            apartmentId: (int) $apartment->id,
            sectionId: (int) $apartment->section_id,
            floor: (int) $apartment->floor,
            primaryPosition: (int) $apartment->position,
            positions: $positions !== [] ? $positions : [(int) $apartment->position],
            riserIds: RiserLink::query()->where('apartment_id', $apartment->id)->pluck('riser_id')
                ->map(static fn ($r): int => (int) $r)->unique()->values()->all(),
        );
    }

    /**
     * Does the incident's scope (and floor range, for notices) cover this apartment?
     */
    public function coveredBy(Incident $incident): bool
    {
        $range = $incident->floorRange();
        if ($range !== null && ($this->floor < $range[0] || $this->floor > $range[1])) {
            return false;
        }

        $key = $incident->scope_key;
        if ($key === 'building') {
            return true;
        }

        if (preg_match('/^section:(\d+)$/', $key, $m) === 1) {
            return (int) $m[1] === $this->sectionId;
        }

        if (preg_match('/^col:(\d+):(\d+)$/', $key, $m) === 1) {
            return (int) $m[1] === $this->sectionId && in_array((int) $m[2], $this->positions, true);
        }

        if (preg_match('/^riser:(\d+)$/', $key, $m) === 1) {
            return in_array((int) $m[1], $this->riserIds, true);
        }

        return false;
    }
}
