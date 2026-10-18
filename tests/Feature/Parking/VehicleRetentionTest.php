<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbRoles;

/*
 * MVP-SPEC C.8 Feature/Parking/VehicleRetentionTest (+ AC 12 third path): prune:vehicles runs as majas_retention and
 * removes vehicles 30 days after permit end / membership end / an unanswered re-confirmation THROUGH
 * VehicleRemovalService — a bound active main permit is revoked (status_reason=retention) with an officer task and
 * an audit entry; fresh vehicles stay. Committed fixtures (the prune connection cannot see the test transaction).
 */
it('prunes expired vehicles as majas_retention through VehicleRemovalService', function (): void {
    $owner = DbRoles::connect('majas_owner');
    [$buildingId, $apts] = retentionBuilding($owner, 'retention-vehicles');
    $userId = (int) $owner->table('users')->insertGetId(['email' => 'ret-'.uniqid().'@example.test', 'locale' => 'lv', 'created_at' => now(), 'updated_at' => now()]);

    try {
        // 1) Unanswered re-confirmation > 30 days, with an ACTIVE main permit bound to it.
        $stale = retentionVehicle($owner, $buildingId, $apts[1], ['reconfirm_due_at' => now()->subDays(45)]);
        $permitId = (int) $owner->table('permits')->insertGetId([
            'building_id' => $buildingId, 'apartment_id' => $apts[1], 'number' => 'P-RET', 'type' => 'main', 'vehicle_id' => $stale,
            'valid_from' => now()->subYear()->toDateString(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 2) Self-declared vehicle, the apartment's only membership ended 40 days ago.
        $orphan = retentionVehicle($owner, $buildingId, $apts[2], ['source' => 'self']);
        $owner->table('apartment_memberships')->insert([
            'building_id' => $buildingId, 'apartment_id' => $apts[2], 'user_id' => $userId, 'role' => 'owner', 'status' => 'ended',
            'ended_at' => now()->subDays(40), 'created_at' => now()->subYear(), 'updated_at' => now(),
        ]);
        // 3) Rejected 31 days ago.
        $rejected = retentionVehicle($owner, $buildingId, $apts[3], ['status' => 'rejected', 'updated_at' => now()->subDays(31)]);
        // 4) Fresh active vehicle with a current main permit — must stay.
        $fresh = retentionVehicle($owner, $buildingId, $apts[4], ['reconfirm_due_at' => now()->addMonths(6)]);
        $owner->table('permits')->insert([
            'building_id' => $buildingId, 'apartment_id' => $apts[4], 'number' => 'P-OK', 'type' => 'main', 'vehicle_id' => $fresh,
            'valid_from' => now()->subMonth()->toDateString(), 'valid_to' => now()->addYear()->toDateString(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        config(['database.connections.pgsql_retention.database' => config('database.connections.pgsql.database')]);
        DB::purge('pgsql_retention');

        $this->artisan('prune:vehicles')->assertSuccessful();

        $remaining = $owner->table('vehicles')->where('building_id', $buildingId)->pluck('id')->all();
        expect($remaining)->toBe([$fresh]);

        $permit = $owner->table('permits')->where('id', $permitId)->first();
        expect($permit->status)->toBe('revoked')
            ->and($permit->status_reason)->toBe('retention')
            ->and($permit->vehicle_id)->toBeNull()
            ->and($owner->table('parking_officer_tasks')->where('building_id', $buildingId)->where('permit_id', $permitId)->value('kind'))->toBe('permit_without_vehicle')
            ->and($owner->table('audit_entries')->where('building_id', $buildingId)->where('action', 'vehicle.remove')->where('reason_code', 'retention')->count())->toBe(3);

        // The retention role still cannot UPDATE vehicles or delete young plate lookups.
        expect(fn () => DbRoles::connect('majas_retention')->update('UPDATE vehicles SET country = ? WHERE id = ?', ['EE', $fresh]))->toThrow(QueryException::class);
    } finally {
        cleanupRetentionBuilding($owner, $buildingId);
        $owner->table('users')->where('id', $userId)->delete();
    }
});
