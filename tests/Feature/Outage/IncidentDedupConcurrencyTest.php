<?php

declare(strict_types=1);

use App\Enums\BuildingRoleType;
use App\Enums\IncidentKind;
use App\Services\Outages\IncidentService;
use App\Services\Outages\ScopeTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbRoles;

/*
 * MVP-SPEC B.2a rule 4 / AC 6: two simultaneous first reports for the same (building, kind, scope) end in ONE open
 * resident incident. Two forked processes run IncidentService::openResidentIncident() on fresh connections with
 * overlapping transactions; the partial UNIQUE index + ON CONFLICT DO NOTHING de-duplicates.
 */
it('creates exactly one incident for two concurrent first reports', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl not available');
    }

    $owner = DbRoles::connect('majas_owner');
    $buildingId = (int) $owner->table('buildings')->insertGetId([
        'slug' => 'dedup-race', 'name' => 'Dedup race', 'address_line' => 'Dedup race', 'address_search' => 'dedup race',
        'city' => 'Rīga', 'floors' => 1, 'settings' => '{}', 'status' => 'draft', 'default_locale' => 'lv',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $sectionId = (int) $owner->table('sections')->insertGetId([
        'building_id' => $buildingId, 'number' => 1, 'first_apartment_no' => 1, 'apartments_per_floor' => 4, 'floors' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $pids = [];

    try {
        foreach ([0, 1] as $p) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                config(['database.connections.race' => config('database.connections.pgsql')]);
                DB::setDefaultConnection('race');
                $result = 'ok';
                try {
                    usleep($p * 100_000); // second process starts while the first transaction is still open
                    DB::transaction(function () use ($buildingId, $sectionId): void {
                        app(IncidentService::class)->openResidentIncident($buildingId, IncidentKind::WaterCold, ScopeTarget::section($sectionId), CarbonImmutable::now(), null);
                        usleep(400_000);
                    });
                } catch (Throwable $e) {
                    $result = 'error: '.$e->getMessage();
                }
                file_put_contents(sys_get_temp_dir()."/dedup-race-{$p}.txt", $result);
                posix_kill(getmypid(), SIGKILL);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        foreach ([0, 1] as $p) {
            $file = sys_get_temp_dir()."/dedup-race-{$p}.txt";
            expect((string) @file_get_contents($file))->toBe('ok');
            @unlink($file);
        }

        expect($owner->table('incidents')->where('building_id', $buildingId)->where('is_open', true)->where('origin', 'resident')->count())->toBe(1);
    } finally {
        $owner->table('incidents')->where('building_id', $buildingId)->delete();
        $owner->table('sections')->where('building_id', $buildingId)->delete();
        $owner->table('buildings')->where('id', $buildingId)->delete();
    }
});

it('joins the same incident for sequential first reports and allows a new one after resolution', function (): void {
    $b = heatingHouse('dedup-seq', 1, 2);
    $u1 = makeUser();
    member($u1, $b, 1);
    $u2 = makeUser();
    member($u2, $b, 5); // same column, same cold-water riser (left side)
    $admin = makeUser();
    grantRole($admin, $b, BuildingRoleType::BuildingAdmin);

    $first = inBuilding($b, fn () => app(IncidentService::class)->report($u1, apartment($b, 1), IncidentKind::WaterCold));
    $second = inBuilding($b, fn () => app(IncidentService::class)->report($u2, apartment($b, 5), IncidentKind::WaterCold));

    expect($second->incidents->first()->id)->toBe($first->incidents->first()->id)
        ->and($second->apartments)->toBe(2)
        ->and($second->incidents->first()->status->value)->toBe('confirmed');

    inBuilding($b, fn () => app(IncidentService::class)->resolve($admin, $second->incidents->first()));
    $third = inBuilding($b, fn () => app(IncidentService::class)->report($u1, apartment($b, 1), IncidentKind::WaterCold));

    expect($third->incidents->first()->id)->not->toBe($first->incidents->first()->id);
});
