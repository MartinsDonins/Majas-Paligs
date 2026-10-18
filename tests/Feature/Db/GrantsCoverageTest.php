<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Every table has the expected grants (information_schema.role_table_grants), MVP-SPEC A.12.
 */
function grantsFor(string $role, string $table): array
{
    return collect(DB::select(
        'SELECT privilege_type FROM information_schema.role_table_grants WHERE grantee = ? AND table_schema = ? AND table_name = ?',
        [$role, 'public', $table],
    ))->pluck('privilege_type')->sort()->values()->all();
}

const APPEND_ONLY = ['audit_entries'];
const READ_ONLY_FOR_APP = ['audit_chain_heads', 'audit_chain_anchors'];

it('grants majas_app full DML on normal tables', function (): void {
    $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))->pluck('tablename')
        ->reject(fn ($t) => in_array($t, [...APPEND_ONLY, ...READ_ONLY_FOR_APP, 'migrations'], true));

    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        expect(grantsFor('majas_app', $table))->toBe(['DELETE', 'INSERT', 'SELECT', 'UPDATE'], "majas_app grants on {$table}");
    }
});

it('gives majas_app SELECT + INSERT only on append-only tables', function (): void {
    foreach (APPEND_ONLY as $table) {
        expect(grantsFor('majas_app', $table))->toBe(['INSERT', 'SELECT']);
    }
});

it('gives majas_app SELECT only on chain heads and anchors', function (): void {
    foreach (READ_ONLY_FOR_APP as $table) {
        expect(grantsFor('majas_app', $table))->toBe(['SELECT']);
    }
});

it('gives majas_retention SELECT + INSERT only on anchors and SELECT on heads', function (): void {
    expect(grantsFor('majas_retention', 'audit_chain_anchors'))->toBe(['INSERT', 'SELECT'])
        ->and(grantsFor('majas_retention', 'audit_chain_heads'))->toBe(['SELECT'])
        ->and(grantsFor('majas_retention', 'audit_entries'))->toBe(['DELETE', 'INSERT', 'SELECT'])
        ->and(grantsFor('majas_retention', 'login_tokens'))->toBe(['DELETE', 'SELECT'])
        ->and(grantsFor('majas_retention', 'heating_reports'))->toBe(['DELETE', 'SELECT'])
        ->and(grantsFor('majas_retention', 'users'))->toBe(['DELETE', 'SELECT']);
});

it('never lets majas_app own anything', function (): void {
    $owned = DB::selectOne("SELECT count(*) AS c FROM pg_class c JOIN pg_roles r ON r.oid = c.relowner WHERE r.rolname = 'majas_app'");
    $functions = DB::selectOne("SELECT count(*) AS c FROM pg_proc p JOIN pg_roles r ON r.oid = p.proowner WHERE r.rolname = 'majas_app'");

    expect((int) $owned->c)->toBe(0)->and((int) $functions->c)->toBe(0);
});

it('uses SECURITY DEFINER only for the chain insert trigger', function (): void {
    $definer = collect(DB::select("SELECT proname FROM pg_proc WHERE prosecdef AND pronamespace = 'public'::regnamespace"))->pluck('proname')->all();
    $guards = collect(DB::select("SELECT proname, prosecdef FROM pg_proc WHERE proname IN ('audit_entries_delete_guard', 'audit_chain_anchors_insert_guard')"));

    expect($definer)->toBe(['audit_entries_chain_insert'])
        ->and($guards)->toHaveCount(2)
        ->and($guards->every(fn ($g) => $g->prosecdef === false))->toBeTrue();
});

it('has the expected role attributes', function (): void {
    $roles = collect(DB::select("SELECT rolname, rolsuper, rolcreaterole, rolbypassrls FROM pg_roles WHERE rolname LIKE 'majas_%'"))->keyBy('rolname');

    expect($roles->keys()->sort()->values()->all())->toBe(['majas_app', 'majas_owner', 'majas_retention']);
    foreach ($roles as $role) {
        expect($role->rolsuper)->toBeFalse()->and($role->rolcreaterole)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();
    }
});
