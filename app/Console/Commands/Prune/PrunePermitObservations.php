<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:permit-observations (PARK-011, ROUND2-SPEC §6.1) — guest-permit plate observations are kept 6 months, like
 * plate lookups. Runs as majas_retention.
 */
#[AsCommand(name: 'prune:permit-observations')]
final class PrunePermitObservations extends Command
{
    protected $signature = 'prune:permit-observations';

    protected $description = 'Delete guest-permit plate observations older than six months.';

    public function handle(): int
    {
        $months = max(1, (int) config('majas.parking.observation_retention_months', 6));
        $count = DB::connection('pgsql_retention')->delete(
            "DELETE FROM permit_plate_observations WHERE observed_on < (now() AT TIME ZONE 'Europe/Riga')::date - make_interval(months => ?)",
            [$months],
        );
        $this->info("Pruned {$count} permit observations.");

        return self::SUCCESS;
    }
}
