<?php

declare(strict_types=1);

namespace App\Services\Buildings;

use App\Models\BuildingSeries;
use App\Models\SeriesApartmentType;
use App\Models\SeriesRiser;
use App\Models\SeriesRoom;
use App\Models\SeriesSectionLayout;
use InvalidArgumentException;

/**
 * Immutable snapshot of a series template for the generator (no DB access during preview).
 */
final readonly class SeriesTemplateData
{
    /**
     * @param  array<string, array{id: int, code: string, apartments_per_floor: int, positions: array<int, array{position: int, side: string, apartment_type_code: string}>}>  $layouts  by code
     * @param  array<string, array{id: int, code: string, rooms_count: int, area_m2: ?string, rooms: list<array{code: string, label: array<string, string>, sort: int, radiator_count: int, towel_dryer: bool}>}>  $types  by code
     * @param  list<array{id: int, layout_code: ?string, code_pattern: string, kind: string, serves: list<array{position: int, room_code: ?string}>, confidence: string, source_ref: ?string}>  $risers
     */
    public function __construct(
        public int $seriesId,
        public string $seriesCode,
        public array $layouts,
        public array $types,
        public array $risers,
        public ?string $defaultLayoutCode,
    ) {}

    public static function fromSeries(BuildingSeries $series): self
    {
        $series->loadMissing(['sectionLayouts', 'apartmentTypes.rooms', 'risers']);

        $layouts = [];
        $default = null;
        /** @var SeriesSectionLayout $layout */
        foreach ($series->sectionLayouts as $layout) {
            $positions = [];
            foreach ($layout->positions as $p) {
                $positions[(int) $p['position']] = [
                    'position' => (int) $p['position'],
                    'side' => (string) $p['side'],
                    'apartment_type_code' => (string) $p['apartment_type_code'],
                ];
            }
            ksort($positions);
            $layouts[$layout->code] = [
                'id' => (int) $layout->id,
                'code' => $layout->code,
                'apartments_per_floor' => $layout->apartments_per_floor,
                'positions' => $positions,
            ];
            if ($layout->is_default) {
                $default = $layout->code;
            }
        }

        $types = [];
        /** @var SeriesApartmentType $type */
        foreach ($series->apartmentTypes as $type) {
            $types[$type->code] = [
                'id' => (int) $type->id,
                'code' => $type->code,
                'rooms_count' => $type->rooms_count,
                'area_m2' => $type->area_m2_typical !== null ? (string) $type->area_m2_typical : null,
                'rooms' => $type->rooms->map(static fn (SeriesRoom $r): array => [
                    'code' => $r->code,
                    'label' => $r->getTranslations('label'),
                    'sort' => $r->sort,
                    'radiator_count' => $r->radiator_count,
                    'towel_dryer' => $r->towel_dryer,
                ])->values()->all(),
            ];
        }

        $layoutCodeById = [];
        foreach ($layouts as $code => $l) {
            $layoutCodeById[$l['id']] = $code;
        }

        $risers = $series->risers->map(static fn (SeriesRiser $r): array => [
            'id' => (int) $r->id,
            'layout_code' => $r->section_layout_id !== null ? ($layoutCodeById[$r->section_layout_id] ?? null) : null,
            'code_pattern' => $r->code_pattern,
            'kind' => $r->kind->value,
            'serves' => array_map(static fn (array $s): array => [
                'position' => (int) $s['position'],
                'room_code' => isset($s['room_code']) ? (string) $s['room_code'] : null,
            ], $r->serves),
            'confidence' => $r->confidence->value,
            'source_ref' => $r->source_ref,
        ])->values()->all();

        return new self((int) $series->id, $series->code, $layouts, $types, $risers, $default);
    }

    /**
     * @return array{id: int, code: string, apartments_per_floor: int, positions: array<int, array{position: int, side: string, apartment_type_code: string}>}
     */
    public function layout(string $code): array
    {
        return $this->layouts[$code] ?? throw new InvalidArgumentException(__('admin.generator.err_preset', ['code' => $code, 'series' => $this->seriesCode]));
    }

    /**
     * @return array{id: int, code: string, rooms_count: int, area_m2: ?string, rooms: list<array{code: string, label: array<string, string>, sort: int, radiator_count: int, towel_dryer: bool}>}
     */
    public function type(string $code): array
    {
        return $this->types[$code] ?? throw new InvalidArgumentException(__('admin.generator.err_type', ['code' => $code, 'series' => $this->seriesCode]));
    }
}
