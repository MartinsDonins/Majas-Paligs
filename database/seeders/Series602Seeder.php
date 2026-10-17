<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BuildingSeries;
use App\Models\SeriesApartmentType;
use App\Models\SeriesRiser;
use App\Models\SeriesSectionLayout;
use Illuminate\Database\Seeder;

/**
 * 602 series template (SERIES-003). Platform data, zero personal data.
 *
 * EVERY riser is a HYPOTHESIS (PLANNING §4.3): room-level heating topology of the 602 series is unconfirmed in
 * public sources (likely vertical single-pipe per room); water risers are grouped by side (left = positions
 * 1–2, right = positions 3–4). Raise confidence only from BIS drawings, the manager or resident confirmation.
 */
final class Series602Seeder extends Seeder
{
    public const SOURCE = '602 template hypothesis 2026-10-17 (public aggregator data + chat evidence of side-specific outages); unverified until BIS drawings';

    public function run(): void
    {
        $series = BuildingSeries::query()->updateOrCreate(['code' => '602'], [
            'name' => ['lv' => '602. sērija (9 stāvu paneļu māja)', 'ru' => '602-я серия (9-этажный панельный дом)'],
            'aliases' => ['602', '602 sērija', 'Lietuviešu projekts'],
            'years_from' => 1970,
            'years_to' => 1992,
            'floors_typical' => [9],
            'wall_material' => 'panel',
            'apartments_per_floor_typical' => 4,
            'notes' => [
                'lv' => 'Šablons ir hipotēze. Stāvadu izvietojums jāapstiprina ar BIS mājas lietas rasējumiem.',
                'ru' => 'Шаблон — гипотеза. Расположение стояков нужно подтвердить чертежами из дела дома в BIS.',
            ],
            'sources' => [
                ['url' => null, 'title' => 'Public aggregator data on Latvian series buildings (unverified)', 'accessed_at' => '2026-10-17'],
            ],
            'status' => 'published',
        ]);

        $types = [
            '1r' => ['rooms' => 1, 'area' => '36.0', 'lv' => '1 istabas dzīvoklis', 'ru' => '1-комнатная квартира'],
            '2r' => ['rooms' => 2, 'area' => '50.0', 'lv' => '2 istabu dzīvoklis', 'ru' => '2-комнатная квартира'],
            '3r' => ['rooms' => 3, 'area' => '62.0', 'lv' => '3 istabu dzīvoklis', 'ru' => '3-комнатная квартира'],
            '4r' => ['rooms' => 4, 'area' => '75.0', 'lv' => '4 istabu dzīvoklis', 'ru' => '4-комнатная квартира'],
        ];

        foreach ($types as $code => $t) {
            $type = SeriesApartmentType::query()->updateOrCreate(
                ['series_id' => $series->id, 'code' => $code],
                ['rooms_count' => $t['rooms'], 'area_m2_typical' => $t['area'], 'label' => ['lv' => $t['lv'], 'ru' => $t['ru']]],
            );

            $rooms = [];
            for ($i = 1; $i <= $t['rooms']; $i++) {
                $rooms[] = ['code' => "room{$i}", 'lv' => "Istaba {$i}", 'ru' => "Комната {$i}", 'radiators' => 1, 'towel' => false];
            }
            $rooms[] = ['code' => 'kitchen', 'lv' => 'Virtuve', 'ru' => 'Кухня', 'radiators' => 1, 'towel' => false];
            $rooms[] = ['code' => 'bath', 'lv' => 'Vannas istaba (dvieļu žāvētājs)', 'ru' => 'Ванная (полотенцесушитель)', 'radiators' => 0, 'towel' => true];
            if ($t['rooms'] >= 2) {
                $rooms[] = ['code' => 'wc', 'lv' => 'Tualete', 'ru' => 'Туалет', 'radiators' => 0, 'towel' => false];
            }

            foreach ($rooms as $sort => $r) {
                $type->rooms()->updateOrCreate(['code' => $r['code']], [
                    'label' => ['lv' => $r['lv'], 'ru' => $r['ru']],
                    'sort' => $sort,
                    'radiator_count' => $r['radiators'],
                    'towel_dryer' => $r['towel'],
                ]);
            }
        }

        // Layout presets: rooms per position 1..4 (positions 1–2 = left, 3–4 = right). Default 2-2-3-3.
        $presets = [
            '2-2-3-3' => ['2r', '2r', '3r', '3r'],
            '1-2-3-4' => ['1r', '2r', '3r', '4r'],
            '1-1-2-2' => ['1r', '1r', '2r', '2r'],
            '1-2-2-3' => ['1r', '2r', '2r', '3r'],
            '2-3-3-3' => ['2r', '3r', '3r', '3r'],
            '2-3-3-4' => ['2r', '3r', '3r', '4r'],
        ];

        foreach ($presets as $code => $typeCodes) {
            $positions = [];
            foreach ($typeCodes as $i => $typeCode) {
                $positions[] = ['position' => $i + 1, 'side' => $i < 2 ? 'left' : 'right', 'apartment_type_code' => $typeCode];
            }

            SeriesSectionLayout::query()->updateOrCreate(['series_id' => $series->id, 'code' => $code], [
                'apartments_per_floor' => 4,
                'positions' => $positions,
                'is_default' => $code === '2-2-3-3',
                'notes' => in_array($code, ['2-3-3-3', '2-3-3-4'], true)
                    ? ['lv' => 'Krievu valodas avotu variants', 'ru' => 'Вариант по русскоязычным источникам']
                    : null,
            ]);
        }

        // Risers (all hypothesis). Layout-independent: heating per position and room, water per side.
        if (SeriesRiser::query()->where('series_id', $series->id)->exists()) {
            return; // keep ids stable: buildings reference series_riser_id
        }

        for ($p = 1; $p <= 4; $p++) {
            SeriesRiser::query()->create([
                'series_id' => $series->id,
                'code_pattern' => 'H{section}-{position}-{room}',
                'kind' => 'heating',
                'serves' => [['position' => $p, 'room_code' => '*']],
                'confidence' => 'hypothesis',
                'source_ref' => self::SOURCE,
            ]);
        }

        foreach (['cold_water' => 'CW', 'hot_water' => 'HW'] as $kind => $prefix) {
            foreach ([[1, 2], [3, 4]] as $positions) {
                SeriesRiser::query()->create([
                    'series_id' => $series->id,
                    'code_pattern' => $prefix.'{section}-{side}',
                    'kind' => $kind,
                    'serves' => array_map(static fn (int $pos): array => ['position' => $pos, 'room_code' => null], $positions),
                    'confidence' => 'hypothesis',
                    'source_ref' => self::SOURCE,
                ]);
            }
        }

        foreach ([[1, 2], [3, 4]] as $positions) {
            SeriesRiser::query()->create([
                'series_id' => $series->id,
                'code_pattern' => 'HWC{section}-{side}',
                'kind' => 'hot_water_circulation',
                'serves' => array_map(static fn (int $pos): array => ['position' => $pos, 'room_code' => 'bath'], $positions),
                'confidence' => 'hypothesis',
                'source_ref' => self::SOURCE.' (towel dryers)',
            ]);
        }
    }
}
