<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:imports (DB-003, ROUND2-SPEC §4.2) — hourly, as majas_retention: a sealed CSV payload never outlives its
 * run's 24 h (open runs become `expired`), and run rows (counts and codes only) are deleted after 30 days. A run that
 * is still `committing` keeps its payload until the job ends.
 */
#[AsCommand(name: 'prune:imports')]
final class PruneImports extends Command
{
    protected $signature = 'prune:imports';

    protected $description = 'Drop expired import payloads and delete import runs older than 30 days.';

    public function handle(): int
    {
        $db = DB::connection('pgsql_retention');

        $expired = $db->update(<<<'SQL'
            UPDATE import_runs
               SET payload_ciphertext = NULL,
                   status = CASE WHEN status IN ('uploaded', 'mapped', 'validated') THEN 'expired' ELSE status END,
                   updated_at = now()
             WHERE expires_at < now() AND status <> 'committing'
               AND (payload_ciphertext IS NOT NULL OR status IN ('uploaded', 'mapped', 'validated'))
            SQL);

        $deleted = $db->delete("DELETE FROM import_runs WHERE created_at < now() - interval '30 days' AND status <> 'committing'");

        $this->info("Expired {$expired} import payloads, deleted {$deleted} import runs.");

        return self::SUCCESS;
    }
}
