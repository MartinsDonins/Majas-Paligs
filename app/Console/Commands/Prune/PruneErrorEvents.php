<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:error-events — the operator error log (app_error_events, OPS-001) keeps an event for 30 days after it was
 * last seen. Runs as majas_retention (SELECT + DELETE only).
 */
#[AsCommand(name: 'prune:error-events')]
final class PruneErrorEvents extends Command
{
    protected $signature = 'prune:error-events';

    protected $description = 'Delete error-log events not seen for 30 days.';

    public function handle(): int
    {
        $count = DB::connection('pgsql_retention')->delete("DELETE FROM app_error_events WHERE last_seen < now() - interval '30 days'");
        $this->info("Pruned {$count} error-log events.");

        return self::SUCCESS;
    }
}
