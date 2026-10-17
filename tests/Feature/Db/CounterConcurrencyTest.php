<?php

declare(strict_types=1);

use App\Models\Building;
use App\Support\CounterService;
use App\Tenancy\CurrentBuilding;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbRoles;

/*
 * Two processes draw numbers from the same counter through CounterService on fresh connections: the result is
 * gap-free and duplicate-free (MVP-SPEC §0, A.12).
 */
it('issues gap-free, duplicate-free numbers under concurrency', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl not available');
    }

    $owner = DbRoles::connect('majas_owner');
    $buildingId = (int) $owner->table('buildings')->insertGetId([
        'slug' => 'counter-race', 'name' => 'Counter race', 'address_line' => 'Counter race', 'address_search' => 'counter race',
        'city' => 'Rīga', 'floors' => 1, 'settings' => '{}', 'status' => 'draft', 'default_locale' => 'lv',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $perProcess = 25;
    $pids = [];

    try {
        for ($p = 0; $p < 2; $p++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // Fresh session WITHOUT closing the inherited socket (that would kill the parent's connection).
                config(['database.connections.race' => config('database.connections.pgsql')]);
                DB::setDefaultConnection('race');
                $values = [];
                $building = Building::query()->findOrFail($buildingId);
                for ($i = 0; $i < $perProcess; $i++) {
                    $values[] = CurrentBuilding::runFor($building, fn () => DB::transaction(fn () => app(CounterService::class)->next('manager_report', 2026)));
                    usleep(random_int(0, 1500));
                }
                file_put_contents(sys_get_temp_dir()."/counter-race-{$p}.json", json_encode($values));
                posix_kill(getmypid(), SIGKILL);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $all = [];
        for ($p = 0; $p < 2; $p++) {
            $file = sys_get_temp_dir()."/counter-race-{$p}.json";
            $all = [...$all, ...json_decode((string) file_get_contents($file), true)];
            @unlink($file);
        }
        sort($all);

        expect($all)->toBe(range(1, 2 * $perProcess))
            ->and((int) $owner->table('building_counters')->where('building_id', $buildingId)->value('value'))->toBe(2 * $perProcess);
    } finally {
        $owner->table('building_counters')->where('building_id', $buildingId)->delete();
        $owner->table('buildings')->where('id', $buildingId)->delete();
    }
});
