<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Building;
use App\Models\BuildingSeries;
use App\Models\LandParcel;
use App\Models\Section;
use App\Platform\PlatformBuildingContext;
use App\Platform\PlatformPurpose;
use App\Services\Buildings\BuildingGenerator;
use App\Services\Buildings\GeneratorInput;
use App\Services\Buildings\SeriesTemplateData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Pilot building Pūces iela 51, Rīga (SERIES-003): 5 stairwells × 9 floors × 4 = 180 apartments numbered
 * 1..180 continuously (stairwell 5 = 145–180; the apartment above n is n+4). STRUCTURE ONLY — no residents,
 * names, phones or plates. Facts from public sources, unverified ones flagged.
 */
final class PucesIela51Seeder extends Seeder
{
    public const SLUG = 'puces-51';

    public function run(): void
    {
        $series = BuildingSeries::query()->where('code', '602')->firstOrFail();

        $building = Building::query()->updateOrCreate(['slug' => self::SLUG], [
            'name' => 'Pūces iela 51',
            'address_line' => 'Pūces iela 51',
            'postcode' => 'LV-1082',
            'city' => 'Rīga',
            'address_code' => '101879497',          // public aggregator data — verify with VZD
            'cadastre_designation' => '01000710114001', // public aggregator data — verify with kadastrs.lv
            'series_id' => $series->id,
            'floors' => 9,
            'year_built' => null,                     // ~1987, unverified
            'manager_name' => 'SIA "Rīgas namu pārvaldnieks"',
            'manager_emergency_phone' => null,        // entered by building_admin (organisation number only)
            'default_locale' => 'lv',
        ]);

        // Layout preset per stairwell = hypothesis 2-2-3-3 until the room mix is confirmed (MANUAL-TODO).
        $input = GeneratorInput::perSection(9, [1 => '2-2-3-3', 2 => '2-2-3-3', 3 => '2-2-3-3', 4 => '2-2-3-3', 5 => '2-2-3-3']);

        PlatformBuildingContext::run($building, PlatformPurpose::Seed, static function (Building $b) use ($series, $input): void {
            app(BuildingGenerator::class)->generate($b, SeriesTemplateData::fromSeries($series), $input);

            $parcelA = LandParcel::query()->updateOrCreate(['cadastre_no' => '01000710114'], [
                'verified' => false,
                'note' => 'Stairwells 4–5 (public data, unverified).',
            ]);
            $parcelB = LandParcel::query()->updateOrCreate(['cadastre_no' => 'TBD-STAIRWELLS-1-3'], [
                'verified' => false,
                'note' => 'Placeholder: second parcel number for stairwells 1–3 is unknown (MANUAL-TODO).',
            ]);

            foreach (Section::query()->get() as $section) {
                $parcel = $section->number >= 4 ? $parcelA : $parcelB;
                DB::table('section_land_parcel')->insertOrIgnore([
                    'building_id' => $b->id,
                    'section_id' => $section->id,
                    'land_parcel_id' => $parcel->id,
                ]);
            }
        });
    }
}
