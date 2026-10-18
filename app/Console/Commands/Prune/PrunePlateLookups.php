<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:plate-lookups — plate lookups are kept 6 months (PLANNING §5). Runs as majas_retention; the DELETE guard
 * trigger refuses anyone else and any younger row.
 */
#[AsCommand(name: 'prune:plate-lookups')]
final class PrunePlateLookups extends Command
{
    protected $signature = 'prune:plate-lookups';

    protected $description = 'Delete plate lookups older than six months.';

    public function handle(): int
    {
        $count = DB::connection('pgsql_retention')->delete("DELETE FROM plate_lookups WHERE created_at < now() - interval '6 months' - interval '1 minute'");
        $this->info("Pruned {$count} plate lookups.");

        return self::SUCCESS;
    }
}
