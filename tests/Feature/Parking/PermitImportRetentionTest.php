<?php

declare(strict_types=1);

use App\Casts\Bytea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DbRoles;

/*
 * Retention of the permit register data (PARK-011 / PARK-012, ROUND2-SPEC §4.2, §6.1, AC-R2-19) — as majas_retention
 * with committed fixtures (the retention connection cannot see the test transaction, so nothing in this file writes
 * inside it): guest-permit observations after 6 months; import payloads after 24 h (open runs → expired), run rows
 * after 30 days (a permit keeps existing, its import_run_id is nulled by the FK).
 */
function retentionConnection(): void
{
    config(['database.connections.pgsql_retention.database' => config('database.connections.pgsql.database')]);
    DB::purge('pgsql_retention');
}

it('prunes observations after 6 months as majas_retention', function (): void {
    $owner = DbRoles::connect('majas_owner');
    [$buildingId, $apts] = retentionBuilding($owner, 'retention-observations');

    try {
        $permitId = (int) $owner->table('permits')->insertGetId([
            'building_id' => $buildingId, 'apartment_id' => $apts[1], 'number' => 'V-1', 'type' => 'guest',
            'valid_from' => '2026-01-01', 'status' => 'active', 'source' => 'import', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = fn (string $on) => [
            'id' => strtolower((string) Str::ulid()), 'building_id' => $buildingId, 'permit_id' => $permitId,
            'plate_ciphertext' => Bytea::literal(random_bytes(40)), 'plate_bidx' => Bytea::literal(random_bytes(32)), 'plate_key_version' => 1,
            'observed_on' => $on, 'source' => 'import', 'created_at' => now(),
        ];
        $old = $row(now('Europe/Riga')->subMonths(6)->subDays(2)->toDateString());
        $recent = $row(now('Europe/Riga')->subMonths(5)->toDateString());
        $owner->table('permit_plate_observations')->insert([$old, $recent]);

        retentionConnection();
        $this->artisan('prune:permit-observations')->assertSuccessful();

        expect($owner->table('permit_plate_observations')->where('id', $old['id'])->exists())->toBeFalse()
            ->and($owner->table('permit_plate_observations')->where('id', $recent['id'])->exists())->toBeTrue();
    } finally {
        $owner->table('permit_plate_observations')->where('building_id', $buildingId)->delete();
        cleanupRetentionBuilding($owner, $buildingId);
    }
});

it('drops import payloads after 24 h and deletes runs after 30 days as majas_retention', function (): void {
    $owner = DbRoles::connect('majas_owner');
    [$buildingId, $apts] = retentionBuilding($owner, 'retention-imports');

    $run = fn (string $status, string $createdAt, string $expiresAt) => [
        'id' => strtolower((string) Str::ulid()), 'building_id' => $buildingId, 'kind' => 'permits', 'status' => $status,
        'file_sha256' => Bytea::literal(random_bytes(32)), 'payload_ciphertext' => in_array($status, ['committed', 'discarded'], true) ? null : Bytea::literal(random_bytes(64)),
        'row_count' => 1, 'expires_at' => $expiresAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
    ];

    try {
        $stale = $run('mapped', now()->subHours(25)->toIso8601String(), now()->subHour()->toIso8601String());
        $fresh = $run('validated', now()->subHour()->toIso8601String(), now()->addHours(23)->toIso8601String());
        $busy = $run('committing', now()->subHours(25)->toIso8601String(), now()->subHour()->toIso8601String());
        $ancient = $run('committed', now()->subDays(31)->toIso8601String(), now()->subDays(30)->toIso8601String());
        $owner->table('import_runs')->insert([$stale, $fresh, $busy, $ancient]);
        $permitId = (int) $owner->table('permits')->insertGetId([
            'building_id' => $buildingId, 'apartment_id' => $apts[1], 'number' => 'V-1', 'type' => 'guest', 'valid_from' => '2026-01-01',
            'status' => 'active', 'source' => 'import', 'import_run_id' => $ancient['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        retentionConnection();
        $this->artisan('prune:imports')->assertSuccessful();

        $get = fn (array $r) => $owner->table('import_runs')->where('id', $r['id'])->first();
        expect($get($stale)->status)->toBe('expired')->and($get($stale)->payload_ciphertext)->toBeNull()
            ->and($get($fresh)->status)->toBe('validated')->and($get($fresh)->payload_ciphertext)->not->toBeNull()
            ->and($get($busy)->status)->toBe('committing')->and($get($busy)->payload_ciphertext)->not->toBeNull()
            ->and($get($ancient))->toBeNull()
            ->and($owner->table('permits')->where('id', $permitId)->value('import_run_id'))->toBeNull();
    } finally {
        $owner->table('permits')->where('building_id', $buildingId)->delete();
        $owner->table('import_runs')->where('building_id', $buildingId)->delete();
        cleanupRetentionBuilding($owner, $buildingId);
    }
});
