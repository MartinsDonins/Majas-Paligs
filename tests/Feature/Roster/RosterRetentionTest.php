<?php

declare(strict_types=1);

use App\Casts\Bytea;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DbRoles;

/*
 * Retention (ROUND2-SPEC §4.2, §5.5) + grants: prune:roster deletes contacts 30 days after "Izvācies" and contacts of
 * archived apartments; prune:imports expires staged files after 24 h (status `expired`, payload nulled) and deletes
 * runs after 30 days. Both run as majas_retention, whose grants are exactly what they need. Committed fixtures (the
 * prune connection cannot see the test transaction).
 */
function grantsOf(string $role, string $table): array
{
    return collect(DB::select(
        'SELECT privilege_type FROM information_schema.role_table_grants WHERE grantee = ? AND table_schema = ? AND table_name = ?',
        [$role, 'public', $table],
    ))->pluck('privilege_type')->sort()->values()->all();
}

it('gives majas_retention exactly the round-2 prune grants', function (): void {
    expect(grantsOf('majas_retention', 'resident_contacts'))->toBe(['DELETE', 'SELECT'])
        ->and(grantsOf('majas_retention', 'import_runs'))->toBe(['DELETE', 'SELECT'])
        ->and(grantsOf('majas_retention', 'import_mapping_presets'))->toBe([])
        ->and(grantsOf('majas_app', 'resident_contacts'))->toBe(['DELETE', 'INSERT', 'SELECT', 'UPDATE'])
        ->and(grantsOf('majas_app', 'import_runs'))->toBe(['DELETE', 'INSERT', 'SELECT', 'UPDATE']);

    $columns = collect(DB::select(
        "SELECT column_name FROM information_schema.column_privileges WHERE grantee = 'majas_retention' AND privilege_type = 'UPDATE' AND table_name = 'import_runs'"
    ))->pluck('column_name')->sort()->values()->all();
    expect($columns)->toBe(['payload_ciphertext', 'status', 'updated_at']);
});

it('prunes moved-out roster contacts after 30 days and expires staged imports after 24 hours', function (): void {
    $owner = DbRoles::connect('majas_owner');
    [$buildingId, $apts] = retentionBuilding($owner, 'retention-roster-'.Str::lower(Str::random(6)));
    $contact = static function (array $attrs) use ($owner, $buildingId, $apts): string {
        $id = strtolower((string) Str::ulid());
        $owner->table('resident_contacts')->insert($attrs + [
            'id' => $id, 'building_id' => $buildingId, 'apartment_id' => $apts[1], 'relation' => 'contact',
            'name_ciphertext' => Bytea::literal(random_bytes(40)), 'key_version' => 1, 'source' => 'admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $run = static function (array $attrs) use ($owner, $buildingId): string {
        $id = strtolower((string) Str::ulid());
        $owner->table('import_runs')->insert($attrs + [
            'id' => $id, 'building_id' => $buildingId, 'kind' => 'roster', 'status' => 'mapped',
            'file_sha256' => Bytea::literal(random_bytes(32)), 'payload_ciphertext' => Bytea::literal(random_bytes(64)),
            'expires_at' => now()->addHours(20), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };

    try {
        $gone = $contact(['moved_out_at' => now()->subDays(31)]);
        $recent = $contact(['moved_out_at' => now()->subDays(5)]);
        $current = $contact([]);
        $archived = $contact(['apartment_id' => $apts[2]]);
        $owner->table('apartments')->where('id', $apts[2])->update(['archived_at' => now()->subDay()]);

        $stale = $run(['expires_at' => now()->subHour(), 'created_at' => now()->subDay()->subHour()]);
        $fresh = $run([]);
        $old = $run(['status' => 'committed', 'payload_ciphertext' => null, 'created_at' => now()->subDays(31)]);

        config(['database.connections.pgsql_retention.database' => config('database.connections.pgsql.database')]);
        DB::purge('pgsql_retention');

        $this->artisan('prune:roster')->assertSuccessful();
        $this->artisan('prune:imports')->assertSuccessful();

        $left = $owner->table('resident_contacts')->where('building_id', $buildingId)->pluck('id')->sort()->values()->all();
        expect($left)->toBe(collect([$recent, $current])->sort()->values()->all())
            ->and(in_array($gone, $left, true) || in_array($archived, $left, true))->toBeFalse();

        $staleRow = $owner->table('import_runs')->where('id', $stale)->first();
        expect($staleRow->status)->toBe('expired')
            ->and($staleRow->payload_ciphertext)->toBeNull()
            ->and($owner->table('import_runs')->where('id', $fresh)->value('payload_ciphertext'))->not->toBeNull()
            ->and($owner->table('import_runs')->where('id', $old)->exists())->toBeFalse();

        // The retention role cannot read-modify roster contents.
        expect(fn () => DbRoles::connect('majas_retention')->update('UPDATE resident_contacts SET relation = ? WHERE id = ?', ['owner', $current]))
            ->toThrow(QueryException::class);
    } finally {
        $owner->table('resident_contacts')->where('building_id', $buildingId)->delete();
        $owner->table('import_runs')->where('building_id', $buildingId)->delete();
        cleanupRetentionBuilding($owner, $buildingId);
    }
});
