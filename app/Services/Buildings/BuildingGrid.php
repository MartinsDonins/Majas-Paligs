<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use App\Models\Apartment;
use App\Models\Section;
use Illuminate\Support\Facades\DB;

/**
 * Builds the grid DTO rendered by <x-ui.building-grid> for the CURRENT building (same shape as
 * BuildingGenerator::preview()): sections → rows (floor desc) → cells. Merged cells show the primary number
 * with a join marker. Structure only — no reports, no people.
 */
final class BuildingGrid
{
    /**
     * @param  list<int>|null  $onlySections  section numbers
     * @return array{sections: list<array{number: int, floors: int, apartments_per_floor: int, first: int, last: int, layout_code: ?string, rows: array<int, array<int, array{number: int, floor: int, position: int, side: ?string, rooms_count: ?int, merged: bool, primary: bool, apartment_id: int}>>}>}
     */
    public function forCurrentBuilding(?array $onlySections = null): array
    {
        $sections = Section::query()->orderBy('number')
            ->when($onlySections !== null, fn ($q) => $q->whereIn('number', $onlySections))
            ->get();

        $apartments = Apartment::query()->whereIn('section_id', $sections->pluck('id'))->get()->keyBy('id');

        $cells = DB::table('apartment_cells')
            ->whereIn('section_id', $sections->pluck('id'))
            ->get()
            ->groupBy('section_id');

        $out = [];

        foreach ($sections as $section) {
            $rows = [];
            for ($floor = $section->floors; $floor >= 1; $floor--) {
                $rows[$floor] = [];
            }

            foreach ($cells->get($section->id, collect()) as $cell) {
                /** @var Apartment|null $apartment */
                $apartment = $apartments->get($cell->apartment_id);
                if ($apartment === null) {
                    continue;
                }

                $rows[(int) $cell->floor][(int) $cell->position] = [
                    'number' => $apartment->number,
                    'floor' => (int) $cell->floor,
                    'position' => (int) $cell->position,
                    'side' => $apartment->side?->value,
                    'rooms_count' => $apartment->rooms_count,
                    'merged' => $apartment->is_override && $apartment->retired_numbers !== [],
                    'primary' => (bool) $cell->is_primary,
                    'apartment_id' => (int) $apartment->id,
                ];
            }

            foreach ($rows as &$row) {
                ksort($row);
            }
            unset($row);

            $out[] = [
                'number' => $section->number,
                'floors' => $section->floors,
                'apartments_per_floor' => $section->apartments_per_floor,
                'first' => $section->first_apartment_no,
                'last' => $section->lastApartmentNo(),
                'layout_code' => $section->layout_code,
                'rows' => $rows,
            ];
        }

        return ['sections' => $out];
    }
}
