<?php

declare(strict_types=1);

use App\Casts\Bytea;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DbRoles;

/*
 * MVP-SPEC C.8 Feature/Parking/SightingRetentionTest (AC 7) + plate lookup retention: photos deleted on day 30
 * (file gone, photo_path cleared, photo_deleted_at set), records after 12 months, lookups after 6 months — all as
 * majas_retention; the plate_lookups DELETE guard refuses younger rows and any other role.
 */
it('deletes photos after 30 days and records after 12 months as majas_retention', function (): void {
    Storage::fake('local');
    $owner = DbRoles::connect('majas_owner');
    [$buildingId, $apts] = retentionBuilding($owner, 'retention-sightings');
    $lotId = (int) $owner->table('parking_lots')->insertGetId(['building_id' => $buildingId, 'name' => 'Pagalms', 'sort' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $row = fn (string $createdAt, ?string $photo) => [
        'id' => strtolower((string) Str::ulid()), 'building_id' => $buildingId, 'parking_lot_id' => $lotId,
        'plate_ciphertext' => Bytea::literal(random_bytes(40)), 'plate_bidx' => Bytea::literal(random_bytes(32)), 'plate_key_version' => 1,
        'seen_at' => $createdAt, 'reporter_apartment_id' => $apts[1], 'photo_path' => $photo, 'photo_bytes' => $photo ? 10 : null,
        'status' => 'open', 'created_at' => $createdAt,
    ];

    try {
        $oldPhoto = $row(now()->subDays(31)->toIso8601String(), "building/{$buildingId}/sightings/old.jpg");
        $newPhoto = $row(now()->subDays(5)->toIso8601String(), "building/{$buildingId}/sightings/new.jpg");
        $ancient = $row(now()->subMonths(13)->toIso8601String(), null);
        foreach ([$oldPhoto, $newPhoto, $ancient] as $r) {
            $owner->table('sightings')->insert($r);
        }
        Storage::disk('local')->put($oldPhoto['photo_path'], 'jpeg');
        Storage::disk('local')->put($newPhoto['photo_path'], 'jpeg');

        config(['database.connections.pgsql_retention.database' => config('database.connections.pgsql.database')]);
        DB::purge('pgsql_retention');

        $this->artisan('prune:sightings')->assertSuccessful();

        Storage::disk('local')->assertMissing($oldPhoto['photo_path']);
        Storage::disk('local')->assertExists($newPhoto['photo_path']);

        $old = $owner->table('sightings')->where('id', $oldPhoto['id'])->first();
        expect($old->photo_path)->toBeNull()->and($old->photo_deleted_at)->not->toBeNull()
            ->and($owner->table('sightings')->where('id', $ancient['id'])->exists())->toBeFalse()
            ->and($owner->table('sightings')->where('id', $newPhoto['id'])->value('photo_path'))->toBe($newPhoto['photo_path']);
    } finally {
        $owner->table('sightings')->where('building_id', $buildingId)->delete();
        $owner->table('parking_lots')->where('building_id', $buildingId)->delete();
        cleanupRetentionBuilding($owner, $buildingId);
    }
});

it('prunes plate lookups after 6 months only as majas_retention (append-only guard)', function (): void {
    $owner = DbRoles::connect('majas_owner');
    [$buildingId] = retentionBuilding($owner, 'retention-lookups');
    $row = fn (string $at) => ['building_id' => $buildingId, 'user_id' => null, 'plate_bidx' => Bytea::literal(random_bytes(32)), 'result' => false, 'reason_code' => 'other', 'created_at' => $at];

    try {
        $owner->table('plate_lookups')->insert($row(now()->subMonths(7)->toIso8601String()));
        $owner->table('plate_lookups')->insert($row(now()->subMonths(5)->toIso8601String()));

        // majas_app may not UPDATE or DELETE (no grant + trigger); retention may not delete young rows.
        $app = DbRoles::connect('majas_app');
        expect(fn () => $app->update('UPDATE plate_lookups SET result = true WHERE building_id = ?', [$buildingId]))->toThrow(QueryException::class)
            ->and(fn () => $app->delete('DELETE FROM plate_lookups WHERE building_id = ?', [$buildingId]))->toThrow(QueryException::class)
            ->and(fn () => DbRoles::connect('majas_retention')->delete("DELETE FROM plate_lookups WHERE building_id = ? AND created_at > now() - interval '6 months'", [$buildingId]))->toThrow(QueryException::class);

        config(['database.connections.pgsql_retention.database' => config('database.connections.pgsql.database')]);
        DB::purge('pgsql_retention');
        $this->artisan('prune:plate-lookups')->assertSuccessful();

        expect($owner->table('plate_lookups')->where('building_id', $buildingId)->count())->toBe(1);
    } finally {
        $owner->statement('ALTER TABLE plate_lookups DISABLE TRIGGER plate_lookups_delete');
        $owner->table('plate_lookups')->where('building_id', $buildingId)->delete();
        $owner->statement('ALTER TABLE plate_lookups ENABLE TRIGGER plate_lookups_delete');
        cleanupRetentionBuilding($owner, $buildingId);
    }
});
