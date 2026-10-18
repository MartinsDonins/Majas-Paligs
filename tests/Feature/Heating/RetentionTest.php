<?php

declare(strict_types=1);

use App\Console\Commands\Prune\PruneHeatingReports;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DbRoles;

/*
 * MVP-SPEC B.7 AC 10 — heating reports are pruned one month after the end of the heating season, by
 * majas_retention (committed fixtures: the prune connection cannot see the test transaction).
 */
it('computes the cutoff as the latest season end whose month of grace is over', function (string $now, string $cutoff): void {
    expect(PruneHeatingReports::cutoff(CarbonImmutable::parse($now, 'Europe/Riga'))->toDateString())->toBe($cutoff);
})->with([
    'autumn' => ['2026-10-18 12:00', '2026-05-31'],
    'winter' => ['2027-01-15 12:00', '2026-05-31'],
    'inside the grace month' => ['2027-06-15 12:00', '2026-05-31'],
    'after the grace month' => ['2027-07-02 12:00', '2027-05-31'],
]);

it('prunes old heating reports as majas_retention and keeps the current season', function (): void {
    $owner = DbRoles::connect('majas_owner');
    $now = now();
    $buildingId = (int) $owner->table('buildings')->insertGetId([
        'slug' => 'retention-heat', 'name' => 'Retention heat', 'address_line' => 'Retention heat', 'address_search' => 'retention heat',
        'city' => 'Rīga', 'floors' => 1, 'settings' => '{}', 'status' => 'draft', 'default_locale' => 'lv', 'created_at' => $now, 'updated_at' => $now,
    ]);

    try {
        $sectionId = (int) $owner->table('sections')->insertGetId(['building_id' => $buildingId, 'number' => 1, 'first_apartment_no' => 1, 'apartments_per_floor' => 1, 'floors' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $apartmentId = (int) $owner->table('apartments')->insertGetId(['building_id' => $buildingId, 'section_id' => $sectionId, 'number' => 1, 'floor' => 1, 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $roomId = (int) $owner->table('rooms')->insertGetId(['building_id' => $buildingId, 'apartment_id' => $apartmentId, 'code' => 'kitchen', 'label' => '{"lv":"Virtuve"}', 'created_at' => $now, 'updated_at' => $now]);

        $row = fn (string $at, ?string $superseded = null) => [
            'building_id' => $buildingId, 'apartment_id' => $apartmentId, 'room_id' => $roomId, 'submission_id' => (string) Str::uuid(),
            'status' => 'cold', 'source' => 'self', 'reported_at' => $at, 'valid_until' => CarbonImmutable::parse($at)->addDays(7),
            'superseded_at' => $superseded, 'created_at' => $at, 'updated_at' => $at,
        ];
        $cutoff = PruneHeatingReports::cutoff(CarbonImmutable::now('Europe/Riga'));
        $owner->table('heating_reports')->insert($row($cutoff->subMonths(3)->toIso8601String(), $cutoff->subMonths(2)->toIso8601String()));
        $owner->table('heating_reports')->insert($row($cutoff->addDay()->toIso8601String()));

        // Point the prune connection at THIS (possibly parallel) test database.
        config(['database.connections.pgsql_retention.database' => config('database.connections.pgsql.database')]);
        DB::purge('pgsql_retention');

        $this->artisan('prune:heating-reports')->assertSuccessful();

        expect($owner->table('heating_reports')->where('building_id', $buildingId)->count())->toBe(1);

        // The retention role may only SELECT + DELETE heating reports, never rewrite them.
        expect(fn () => DbRoles::connect('majas_retention')->update('UPDATE heating_reports SET status = ? WHERE building_id = ?', ['warm', $buildingId]))
            ->toThrow(QueryException::class);
    } finally {
        foreach (['heating_reports', 'rooms', 'apartments', 'sections'] as $table) {
            $owner->table($table)->where('building_id', $buildingId)->delete();
        }
        $owner->table('buildings')->where('id', $buildingId)->delete();
    }
});
