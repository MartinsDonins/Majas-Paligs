<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:roster (ROUND2-SPEC §5.5) — as majas_retention: roster contacts marked "Izvācies" more than 30 days ago and
 * contacts of archived (merged / removed) apartments. Counts only in the output.
 */
#[AsCommand(name: 'prune:roster')]
final class PruneRoster extends Command
{
    protected $signature = 'prune:roster';

    protected $description = 'Delete roster contacts 30 days after "moved out" and contacts of archived apartments.';

    public function handle(): int
    {
        $days = max(1, (int) config('majas.roster.moved_out_keep_days', 30));

        $count = DB::connection('pgsql_retention')->delete(
            'DELETE FROM resident_contacts c
              WHERE (c.moved_out_at IS NOT NULL AND c.moved_out_at < now() - make_interval(days => ?))
                 OR EXISTS (SELECT 1 FROM apartments a WHERE a.id = c.apartment_id AND a.building_id = c.building_id AND a.archived_at IS NOT NULL)',
            [$days],
        );
        $this->info("Pruned {$count} roster contacts.");

        return self::SUCCESS;
    }
}
