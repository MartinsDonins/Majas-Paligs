<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The database itself rejects cross-building child rows (composite FKs (building_id, parent_id)).
 */
it('rejects child rows that point into another building', function (string $table, Closure $row): void {
    $a = makeBuilding('fk-a', 1, 1);
    $b = makeBuilding('fk-b', 1, 1);

    DB::table($table)->insert($row($a, $b));
})->with([
    'apartment in foreign section' => ['apartments', fn ($a, $b) => [
        'building_id' => $a->id, 'section_id' => section($b, 1)->id, 'number' => 999, 'floor' => 1, 'position' => 9,
        'retired_numbers' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]],
    'room in foreign apartment' => ['rooms', fn ($a, $b) => [
        'building_id' => $a->id, 'apartment_id' => apartment($b, 1)->id, 'code' => 'x', 'label' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]],
    'membership in foreign apartment' => ['apartment_memberships', fn ($a, $b) => [
        'building_id' => $a->id, 'apartment_id' => apartment($b, 1)->id, 'user_id' => makeUser()->id, 'role' => 'owner', 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]],
    'invite for foreign apartment' => ['apartment_invites', fn ($a, $b) => [
        'id' => (string) Str::ulid(), 'building_id' => $a->id, 'apartment_id' => apartment($b, 1)->id,
        'code_hmac' => 'x', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
    ]],
    'elder role on foreign section' => ['building_roles', fn ($a, $b) => [
        'building_id' => $a->id, 'user_id' => makeUser()->id, 'role' => 'section_elder', 'section_id' => section($b, 1)->id,
        'granted_at' => now(), 'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
    ]],
    'cell of foreign apartment' => ['apartment_cells', fn ($a, $b) => [
        'building_id' => $a->id, 'section_id' => section($a, 1)->id, 'floor' => 7, 'position' => 1, 'apartment_id' => apartment($b, 1)->id,
    ]],
])->throws(QueryException::class);

it('requires a section for elders (CHECK)', function (): void {
    $a = makeBuilding('fk-c', 1, 1);

    DB::table('building_roles')->insert([
        'building_id' => $a->id, 'user_id' => makeUser()->id, 'role' => 'section_elder', 'section_id' => null,
        'granted_at' => now(), 'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
    ]);
})->throws(QueryException::class);
