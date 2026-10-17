<?php

declare(strict_types=1);

use App\Enums\NumberingDirection;
use App\Models\Apartment;
use App\Models\ApartmentCell;
use App\Models\Building;
use App\Models\Riser;
use App\Models\RiserLink;
use App\Models\Room;
use App\Models\Section;
use App\Services\Buildings\ApartmentMerger;
use App\Services\Buildings\BuildingGenerator;
use App\Services\Buildings\GeneratorInput;
use App\Services\Buildings\SeriesTemplateData;
use App\Tenancy\Exceptions\MissingBuildingContext;
use Database\Seeders\PucesIela51Seeder;
use Illuminate\Support\Facades\DB;

it('numbers apartments with the formula and both directions', function (): void {
    expect(BuildingGenerator::number(145, 1, 1, 4))->toBe(145)
        ->and(BuildingGenerator::number(145, 1, 4, 4))->toBe(148)
        ->and(BuildingGenerator::number(145, 2, 1, 4))->toBe(149)
        ->and(BuildingGenerator::number(145, 9, 4, 4))->toBe(180)
        ->and(BuildingGenerator::number(1, 1, 1, 4, NumberingDirection::Rtl))->toBe(4);
});

it('previews the 602 pilot building 5 × 9 × 4 = 180 without writing', function (): void {
    $template = SeriesTemplateData::fromSeries(series602());
    $before = DB::table('apartments')->count();

    $preview = app(BuildingGenerator::class)->preview($template, GeneratorInput::uniform(5, 9, '2-2-3-3'));
    $again = app(BuildingGenerator::class)->preview($template, GeneratorInput::uniform(5, 9, '2-2-3-3'));

    expect($preview)->toEqual($again)
        ->and($preview['apartments'])->toBe(180)
        ->and($preview['sections'])->toHaveCount(5)
        ->and($preview['sections'][4]['first'])->toBe(145)
        ->and($preview['sections'][4]['last'])->toBe(180)
        ->and($preview['sections'][4]['rows'][9][4]['number'])->toBe(180)
        ->and(DB::table('apartments')->count())->toBe($before);
});

it('seeds Pūces iela 51: 5 sections, 180 apartments 1..180, stairwell 5 = 145..180, above = n+4', function (): void {
    series602();
    (new PucesIela51Seeder)->run();

    $building = Building::query()->where('slug', PucesIela51Seeder::SLUG)->firstOrFail();

    inBuilding($building, function (): void {
        expect(Section::query()->count())->toBe(5)
            ->and(Apartment::query()->count())->toBe(180)
            ->and(Apartment::query()->min('number'))->toBe(1)
            ->and(Apartment::query()->max('number'))->toBe(180);

        $s5 = Section::query()->where('number', 5)->firstOrFail();
        $numbers = Apartment::query()->where('section_id', $s5->id)->orderBy('number')->pluck('number')->all();
        expect($numbers)->toBe(range(145, 180));

        foreach (Apartment::query()->where('floor', '<', 9)->get() as $apartment) {
            $above = Apartment::query()
                ->where('section_id', $apartment->section_id)
                ->where('floor', $apartment->floor + 1)
                ->where('position', $apartment->position)
                ->firstOrFail();
            expect($above->number)->toBe($apartment->number + 4);
        }

        // every riser is a hypothesis until confirmed
        expect(Riser::query()->where('confidence', '!=', 'hypothesis')->count())->toBe(0)
            ->and(Riser::query()->count())->toBeGreaterThan(0)
            ->and(RiserLink::query()->count())->toBeGreaterThan(0);
    });
});

it('is idempotent and keeps overrides (merged apartments) on re-generation', function (): void {
    $building = makeBuilding('gen-house', 1, 3);
    $template = SeriesTemplateData::fromSeries(series602());

    inBuilding($building, function (Building $b) use ($template): void {
        $counts = fn () => [Apartment::query()->count(), Room::query()->count(), Riser::query()->count(), RiserLink::query()->count(), ApartmentCell::query()->count()];
        $before = $counts();

        $primary = Apartment::query()->where('number', 1)->firstOrFail();
        $absorbed = Apartment::query()->where('number', 2)->firstOrFail();
        app(ApartmentMerger::class)->merge($primary, $absorbed, 'merged in 2001');

        $second = app(BuildingGenerator::class)->generate($b, $template, GeneratorInput::uniform(1, 3, '2-2-3-3'));

        expect($second['apartments'])->toBe(0)
            ->and(Apartment::query()->where('number', 2)->exists())->toBeFalse()
            ->and(Apartment::query()->where('number', 1)->firstOrFail()->is_override)->toBeTrue()
            ->and(ApartmentCell::query()->count())->toBe($before[4])
            ->and(ApartmentCell::query()->where('apartment_id', $primary->id)->count())->toBe(2);
    });
});

it('refuses to generate outside the building context', function (): void {
    $building = Building::query()->create(['slug' => 'x', 'name' => 'x', 'address_line' => 'x', 'city' => 'Rīga', 'floors' => 9]);

    app(BuildingGenerator::class)->generate($building, SeriesTemplateData::fromSeries(series602()), GeneratorInput::uniform(1, 2, '2-2-3-3'));
})->throws(MissingBuildingContext::class);
