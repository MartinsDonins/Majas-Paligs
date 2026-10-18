<?php

declare(strict_types=1);

namespace App\Services\Outages;

use App\Models\Incident;
use App\Models\RiserLink;
use Illuminate\Support\Collection;

/**
 * Outage overlay of one stairwell grid (DESIGN-SYSTEM §2.3 "Outage overlay"): which cells ("floor:position" =>
 * incident kinds) sit inside the scope of an open incident (notice or resident incident). Scope-level only —
 * a riser / column / stairwell, never "who".
 */
final class OutageOverlay
{
    /**
     * @param  array{number: int, rows: array<int, array<int, array{apartment_id: int}>>}  $gridSection  BuildingGrid section
     * @return array{cells: array<string, list<string>>, incidents: Collection<int, Incident>}
     */
    public function forSection(int $sectionId, array $gridSection): array
    {
        $incidents = Incident::query()->open()
            ->withCount('confirmations')
            ->with('riser:id,code,section_id')
            ->where(fn ($q) => $q->where('section_id', $sectionId)->orWhere('scope_key', 'building'))
            ->orderByDesc('started_at')
            ->get();

        $riserApartments = [];
        $riserIds = $incidents->pluck('riser_id')->filter()->unique()->values();
        if ($riserIds->isNotEmpty()) {
            foreach (RiserLink::query()->whereIn('riser_id', $riserIds)->get(['riser_id', 'apartment_id']) as $l) {
                $riserApartments[(int) $l->riser_id][(int) $l->apartment_id] = true;
            }
        }

        $cells = [];
        foreach ($gridSection['rows'] as $floor => $row) {
            foreach ($row as $position => $cell) {
                foreach ($incidents as $incident) {
                    if ($this->covers($incident, $sectionId, (int) $floor, (int) $position, (int) $cell['apartment_id'], $riserApartments)) {
                        $cells[$floor.':'.$position][] = $incident->kind->value;
                    }
                }
                if (isset($cells[$floor.':'.$position])) {
                    $cells[$floor.':'.$position] = array_values(array_unique($cells[$floor.':'.$position]));
                }
            }
        }

        return ['cells' => $cells, 'incidents' => $incidents];
    }

    /**
     * @param  array<int, array<int, true>>  $riserApartments
     */
    private function covers(Incident $incident, int $sectionId, int $floor, int $position, int $apartmentId, array $riserApartments): bool
    {
        $range = $incident->floorRange();
        if ($range !== null && ($floor < $range[0] || $floor > $range[1])) {
            return false;
        }

        $key = $incident->scope_key;

        return match (true) {
            $key === 'building' => true,
            str_starts_with($key, 'section:') => (int) $incident->section_id === $sectionId,
            str_starts_with($key, 'col:') => (int) $incident->section_id === $sectionId && (int) $incident->column_position === $position,
            str_starts_with($key, 'riser:') => isset($riserApartments[(int) $incident->riser_id][$apartmentId]),
            default => false,
        };
    }
}
