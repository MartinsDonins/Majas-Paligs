<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;

/*
 * AC 20: a fresh cluster bootstrapped ONLY by database/sql/roles.sql migrates from zero as majas_owner.
 * The end-to-end run needs a PostgreSQL superuser and psql, so it is executed by scripts/bootstrap-check.sh
 * (CI job step `bootstrap-check`). Here we assert the static preconditions that make it possible.
 */
it('keeps every superuser-only statement inside roles.sql', function (): void {
    $sql = (string) file_get_contents(base_path('database/sql/roles.sql'));

    expect($sql)->toContain('CREATE ROLE majas_owner')
        ->toContain('CREATE ROLE majas_app')
        ->toContain('CREATE ROLE majas_retention')
        ->toContain('OWNER majas_owner')
        ->toContain('ALTER SCHEMA public OWNER TO majas_owner')
        ->toContain('REVOKE CREATE ON SCHEMA public FROM PUBLIC')
        ->toContain('CREATE EXTENSION IF NOT EXISTS btree_gist')
        ->toContain('CREATE EXTENSION IF NOT EXISTS citext')
        ->toContain('CREATE EXTENSION IF NOT EXISTS pg_trgm');
});

it('never needs superuser or role management inside migrations', function (): void {
    $forbidden = ['CREATE ROLE', 'ALTER ROLE', 'CREATE DATABASE', 'ALTER SYSTEM', 'CREATE LANGUAGE'];

    foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
        $source = strtoupper((string) file_get_contents($file));
        foreach ($forbidden as $needle) {
            expect(str_contains($source, $needle))->toBeFalse(basename($file)." contains {$needle}");
        }
    }
});

it('is migrated by majas_owner (current test database owner)', function (): void {
    $owner = DB::selectOne('SELECT pg_get_userbyid(datdba) AS owner FROM pg_database WHERE datname = current_database()');

    expect($owner->owner)->toBe('majas_owner');
});
