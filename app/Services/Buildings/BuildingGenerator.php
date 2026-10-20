<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use App\Enums\NumberingDirection;
use App\Enums\RiserConfidence;
use App\Models\Apartment;
use App\Models\Building;
use App\Models\Riser;
use App\Models\Room;
use App\Models\Section;
use App\Tenancy\CurrentBuilding;
use App\Tenancy\Exceptions\MissingBuildingContext;
use Illuminate\Support\Facades\DB;

/**
 * Generates a building's structure from a series template (MVP-SPEC A.5, SERIES-002).
 *
 * Numbering: number = section.first_apartment_no + (floor-1)*apartments_per_floor + (position-1) for `ltr`
 * (`rtl` mirrors the position). preview() is pure and idempotent (no DB writes, no context needed);
 * generate() writes sections, apartments, cells, rooms, risers and riser links in ONE transaction inside the
 * building's context and is idempotent: existing rows (incl. overrides such as merged apartments) are kept.
 *
 * "Idempotent" only holds for the SAME inputs. Re-running with another numbering, floor count or preset used to
 * link risers and rooms across stairwells (QA 2026-10-20: 226 of 634 riser links pointed into another stairwell).
 * generate() therefore refuses any input that conflicts with the stored structure (conflicts()), and every lookup
 * is scoped to the stairwell being generated.
 */
final class BuildingGenerator
{
    /**
     * @return array{sections: list<array{number: int, floors: int, apartments_per_floor: int, first: int, last: int, layout_code: string, rows: array<int, array<int, array{number: int, floor: int, position: int, side: string, type_code: string, rooms_count: int}>>}>, apartments: int, risers: int}
     */
    public function preview(SeriesTemplateData $template, GeneratorInput $input): array
    {
        $sections = [];
        $next = $input->firstApartmentNo;
        $apartments = 0;
        $risers = 0;

        foreach ($input->sections as $s) {
            $layout = $template->layout($s['layout_code']);
            $apf = $layout['apartments_per_floor'];
            $first = $s['first_apartment_no'] ?? $next;
            $floors = (int) $s['floors'];

            $rows = [];
            for ($floor = $floors; $floor >= 1; $floor--) {
                $row = [];
                foreach ($layout['positions'] as $position => $p) {
                    $type = $template->type($p['apartment_type_code']);
                    $row[$position] = [
                        'number' => self::number($first, $floor, $position, $apf, $input->direction),
                        'floor' => $floor,
                        'position' => $position,
                        'side' => $p['side'],
                        'type_code' => $type['code'],
                        'rooms_count' => $type['rooms_count'],
                    ];
                    $apartments++;
                }
                $rows[$floor] = $row;
            }

            $risers += count($this->riserPlan($template, $s['layout_code'], (int) $s['number']));

            $sections[] = [
                'number' => (int) $s['number'],
                'floors' => $floors,
                'apartments_per_floor' => $apf,
                'first' => $first,
                'last' => $first + $floors * $apf - 1,
                'layout_code' => $s['layout_code'],
                'rows' => $rows,
            ];

            $next = $first + $floors * $apf;
        }

        return ['sections' => $sections, 'apartments' => $apartments, 'risers' => $risers];
    }

    public static function number(int $first, int $floor, int $position, int $apartmentsPerFloor, NumberingDirection $direction = NumberingDirection::Ltr): int
    {
        $pos = $direction === NumberingDirection::Rtl ? $apartmentsPerFloor - $position + 1 : $position;

        return $first + ($floor - 1) * $apartmentsPerFloor + ($pos - 1);
    }

    /**
     * Write the structure. Must run inside the building's context (PlatformBuildingContext::run(…)).
     *
     * @return array{sections: int, apartments: int, rooms: int, risers: int, riser_links: int}
     */
    public function generate(Building $building, SeriesTemplateData $template, GeneratorInput $input): array
    {
        if (! CurrentBuilding::has() || CurrentBuilding::id() !== (int) $building->id) {
            throw new MissingBuildingContext('BuildingGenerator::generate() requires the building context (PlatformBuildingContext).');
        }

        $preview = $this->preview($template, $input);

        $conflicts = $this->conflicts($template, $preview);
        if ($conflicts !== []) {
            throw new GeneratorConflict(implode(' ', $conflicts));
        }

        return DB::transaction(function () use ($template, $preview): array {
            $created = ['sections' => 0, 'apartments' => 0, 'rooms' => 0, 'risers' => 0, 'riser_links' => 0];

            foreach ($preview['sections'] as $ps) {
                $this->generateSection($template, $ps, $created);
            }

            return $created;
        });
    }

    /**
     * Why this preview cannot be applied to the building's stored structure (empty = safe to generate). Needs the
     * building context. A stairwell that already exists must be generated with exactly its stored first number,
     * floors, apartments per floor, layout preset and numbering direction; apartment numbers and riser codes may
     * not belong to another stairwell.
     *
     * @param  array{sections: list<array{number: int, floors: int, apartments_per_floor: int, first: int, last: int, layout_code: string, rows: array<int, array<int, array{number: int, floor: int, position: int, side: string, type_code: string, rooms_count: int}>>}>, apartments: int, risers: int}  $preview
     * @return list<string>
     */
    public function conflicts(SeriesTemplateData $template, array $preview): array
    {
        if (! CurrentBuilding::has()) {
            throw new MissingBuildingContext('BuildingGenerator::conflicts() requires the building context.');
        }

        $messages = [];
        $stored = Section::query()->get()->keyBy('number');

        foreach ($preview['sections'] as $ps) {
            /** @var Section|null $section */
            $section = $stored->get($ps['number']);

            if ($section !== null) {
                $first = (int) $section->first_apartment_no;
                $last = $first + (int) $section->floors * (int) $section->apartments_per_floor - 1;
                if ($first !== $ps['first'] || (int) $section->floors !== $ps['floors']
                    || (int) $section->apartments_per_floor !== $ps['apartments_per_floor'] || (string) $section->layout_code !== $ps['layout_code']) {
                    $messages[] = __('admin.generator.conflict_section', [
                        'n' => $ps['number'],
                        'existing' => __('admin.generator.structure_line', ['from' => $first, 'to' => $last, 'floors' => (int) $section->floors, 'layout' => (string) $section->layout_code]),
                    ]);

                    continue;
                }

                $sample = Apartment::query()->where('section_id', $section->id)->where('floor', 1)->where('position', 1)->value('number');
                $expected = $ps['rows'][1][1]['number'] ?? null;
                if ($sample !== null && $expected !== null && (int) $sample !== (int) $expected) {
                    $messages[] = __('admin.generator.conflict_direction', ['n' => $ps['number']]);

                    continue;
                }
            }

            $taken = Apartment::query()
                ->whereBetween('number', [$ps['first'], $ps['last']])
                ->when($section !== null, fn ($q) => $q->where('section_id', '!=', $section->id))
                ->orderBy('number')
                ->pluck('number');
            if ($taken->isNotEmpty()) {
                $messages[] = __('admin.generator.conflict_numbers', ['n' => $ps['number'], 'from' => $taken->first(), 'to' => $taken->last()]);
            }

            $codes = array_column($this->riserPlan($template, $ps['layout_code'], $ps['number']), 'code');
            $foreign = Riser::query()
                ->whereIn('code', $codes)
                ->when($section !== null, fn ($q) => $q->where('section_id', '!=', $section->id))
                ->orderBy('code')
                ->pluck('code');
            if ($foreign->isNotEmpty()) {
                $messages[] = __('admin.generator.conflict_risers', ['n' => $ps['number'], 'codes' => $foreign->take(3)->implode(', ')]);
            }
        }

        return $messages;
    }

    /**
     * @param  array{number: int, floors: int, apartments_per_floor: int, first: int, last: int, layout_code: string, rows: array<int, array<int, array{number: int, floor: int, position: int, side: string, type_code: string, rooms_count: int}>>}  $ps
     * @param  array{sections: int, apartments: int, rooms: int, risers: int, riser_links: int}  $created
     */
    private function generateSection(SeriesTemplateData $template, array $ps, array &$created): void
    {
        $buildingId = CurrentBuilding::id();
        $now = now();
        $layout = $template->layout($ps['layout_code']);

        $section = Section::query()->where('number', $ps['number'])->first();
        if ($section === null) {
            $section = Section::query()->create([
                'number' => $ps['number'],
                'first_apartment_no' => $ps['first'],
                'apartments_per_floor' => $ps['apartments_per_floor'],
                'floors' => $ps['floors'],
                'layout_code' => $ps['layout_code'],
                'series_section_layout_id' => $layout['id'],
            ]);
            $created['sections']++;
        }

        // Existing cells keep their owner (e.g. a cell absorbed by a merge).
        $cells = DB::table('apartment_cells')
            ->where('building_id', $buildingId)
            ->where('section_id', $section->id)
            ->get()
            ->keyBy(static fn (object $c): string => $c->floor.':'.$c->position);

        $numbers = [];
        foreach ($ps['rows'] as $row) {
            foreach ($row as $cell) {
                $numbers[] = $cell['number'];
            }
        }
        // Only THIS stairwell's apartments (conflicts() guarantees no other stairwell uses these numbers).
        $existing = Apartment::query()->where('section_id', $section->id)->whereIn('number', $numbers)->get()->keyBy('number');

        // 1. Missing apartments (bulk).
        $newApartments = [];
        foreach ($ps['rows'] as $floor => $row) {
            foreach ($row as $position => $cell) {
                if ($cells->has($floor.':'.$position) || $existing->has($cell['number'])) {
                    continue;
                }
                $type = $template->type($cell['type_code']);
                $newApartments[] = [
                    'building_id' => $buildingId,
                    'section_id' => $section->id,
                    'number' => $cell['number'],
                    'floor' => $floor,
                    'position' => $position,
                    'side' => $cell['side'],
                    'rooms_count' => $type['rooms_count'],
                    'area_m2' => $type['area_m2'],
                    'series_apartment_type_id' => $type['id'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if ($newApartments !== []) {
            DB::table('apartments')->insert($newApartments);
            $created['apartments'] += count($newApartments);
            $existing = Apartment::query()->where('section_id', $section->id)->whereIn('number', $numbers)->get()->keyBy('number');
        }

        // 2. Cells.
        $byCell = [];
        $newCells = [];
        foreach ($ps['rows'] as $floor => $row) {
            foreach ($row as $position => $cell) {
                $key = $floor.':'.$position;
                if ($cells->has($key)) {
                    $byCell[$key] = (int) $cells->get($key)->apartment_id;

                    continue;
                }
                $apartment = $existing->get($cell['number']);
                if ($apartment === null) {
                    continue;
                }
                $byCell[$key] = (int) $apartment->id;
                $newCells[] = [
                    'building_id' => $buildingId,
                    'section_id' => $section->id,
                    'floor' => $floor,
                    'position' => $position,
                    'apartment_id' => $apartment->id,
                    'is_primary' => true,
                ];
            }
        }
        if ($newCells !== []) {
            DB::table('apartment_cells')->insertOrIgnore($newCells);
        }

        // 3. Rooms from the template (never for override apartments).
        $apartmentIds = array_values(array_unique($byCell));
        $roomMap = $this->roomMap($apartmentIds);
        $newRooms = [];
        foreach ($ps['rows'] as $floor => $row) {
            foreach ($row as $position => $cell) {
                $apartment = $existing->get($cell['number']);
                if ($apartment === null || $apartment->is_override || ($byCell[$floor.':'.$position] ?? null) !== (int) $apartment->id) {
                    continue;
                }
                foreach ($template->type($cell['type_code'])['rooms'] as $r) {
                    if (isset($roomMap[$apartment->id][$r['code']])) {
                        continue;
                    }
                    $newRooms[] = [
                        'building_id' => $buildingId,
                        'apartment_id' => $apartment->id,
                        'code' => $r['code'],
                        'label' => json_encode($r['label'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'sort' => $r['sort'],
                        'radiator_count' => $r['radiator_count'],
                        'towel_dryer' => $r['towel_dryer'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }
        foreach (array_chunk($newRooms, 500) as $chunk) {
            DB::table('rooms')->insert($chunk);
        }
        if ($newRooms !== []) {
            $created['rooms'] += count($newRooms);
            $roomMap = $this->roomMap($apartmentIds);
        }

        // 4. Risers + links.
        $risers = Riser::query()->where('section_id', $section->id)->get()->keyBy('code');
        $links = [];
        foreach ($this->riserPlan($template, $ps['layout_code'], $ps['number']) as $plan) {
            $riser = $risers->get($plan['code']);
            if ($riser === null) {
                $riser = Riser::query()->create([
                    'section_id' => $section->id,
                    'code' => $plan['code'],
                    'kind' => $plan['kind'],
                    'position' => $plan['position'],
                    'side' => $plan['side'],
                    'floors_from' => 1,
                    'floors_to' => $ps['floors'],
                    'confidence' => $plan['confidence'] ?: RiserConfidence::Hypothesis->value,
                    'source_ref' => $plan['source_ref'],
                    'series_riser_id' => $plan['series_riser_id'],
                ]);
                $created['risers']++;
            }

            for ($floor = 1; $floor <= $ps['floors']; $floor++) {
                foreach ($plan['serves'] as $serve) {
                    $apartmentId = $byCell[$floor.':'.$serve['position']] ?? null;
                    if ($apartmentId === null) {
                        continue;
                    }
                    $roomId = null;
                    if ($serve['room_code'] !== null) {
                        $roomId = $roomMap[$apartmentId][$serve['room_code']] ?? null;
                        if ($roomId === null) {
                            continue;
                        }
                    }
                    $links[] = [
                        'building_id' => $buildingId,
                        'riser_id' => $riser->id,
                        'apartment_id' => $apartmentId,
                        'room_id' => $roomId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }
        foreach (array_chunk($links, 500) as $chunk) {
            $created['riser_links'] += DB::table('riser_links')->insertOrIgnore($chunk);
        }
    }

    /**
     * Concrete risers of one section. Placeholders: {section}, {position}, {side} (K = kreisā / L = labā), {room}.
     * A serve entry with room_code "*" expands to one riser per room with a radiator of the apartment type at
     * that position.
     *
     * @return list<array{code: string, kind: string, position: ?int, side: ?string, serves: list<array{position: int, room_code: ?string}>, confidence: string, source_ref: ?string, series_riser_id: int}>
     */
    public function riserPlan(SeriesTemplateData $template, string $layoutCode, int $sectionNumber): array
    {
        $layout = $template->layout($layoutCode);
        $plans = [];

        foreach ($template->risers as $tr) {
            if ($tr['layout_code'] !== null && $tr['layout_code'] !== $layoutCode) {
                continue;
            }

            $expanded = [];
            foreach ($tr['serves'] as $serve) {
                if (! isset($layout['positions'][$serve['position']])) {
                    continue;
                }
                if ($serve['room_code'] === '*') {
                    $type = $template->type($layout['positions'][$serve['position']]['apartment_type_code']);
                    foreach ($type['rooms'] as $room) {
                        if ($room['radiator_count'] < 1) {
                            continue; // "*" = every room with a radiator
                        }
                        $expanded[$room['code']][] = ['position' => $serve['position'], 'room_code' => $room['code']];
                    }
                } else {
                    $expanded[$serve['room_code'] ?? ''][] = $serve;
                }
            }

            foreach ($expanded as $roomKey => $serves) {
                $positions = array_values(array_unique(array_map(static fn (array $s): int => $s['position'], $serves)));
                $sides = array_values(array_unique(array_map(static fn (int $p): string => $layout['positions'][$p]['side'], $positions)));
                $position = count($positions) === 1 ? $positions[0] : null;
                $side = count($sides) === 1 ? $sides[0] : null;

                $code = strtr($tr['code_pattern'], [
                    '{section}' => (string) $sectionNumber,
                    '{position}' => (string) ($position ?? ''),
                    '{side}' => $side === 'left' ? 'K' : ($side === 'right' ? 'L' : ''),
                    '{room}' => strtoupper((string) $roomKey),
                ]);

                $plans[$code] = [
                    'code' => $code,
                    'kind' => $tr['kind'],
                    'position' => $position,
                    'side' => $side,
                    'serves' => $serves,
                    'confidence' => $tr['confidence'],
                    'source_ref' => $tr['source_ref'],
                    'series_riser_id' => $tr['id'],
                ];
            }
        }

        return array_values($plans);
    }

    /**
     * @param  list<int>  $apartmentIds
     * @return array<int, array<string, int>> apartment_id => code => room_id
     */
    private function roomMap(array $apartmentIds): array
    {
        $map = [];
        foreach (Room::query()->whereIn('apartment_id', $apartmentIds)->get(['id', 'apartment_id', 'code']) as $room) {
            $map[(int) $room->apartment_id][$room->code] = (int) $room->id;
        }

        return $map;
    }
}
