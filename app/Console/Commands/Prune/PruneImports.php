<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:imports (DB-003, ROUND2-SPEC §4.2) — hourly, as majas_retention: a staged CSV never outlives its 24 h resume
 * window (status `expired`, payload nulled; a run still `committing` is left to its job), and run rows are deleted 30
 * days after creation (counts and codes only, but no reason to keep them).
 */
#[AsCommand(name: 'prune:imports')]
final class PruneImports extends Command
{
    protected $signature = 'prune:imports';

    protected $description = 'Expire staged import files after 24 hours and delete import runs after 30 days.';

    public function handle(): int
    {
        $db = DB::connection('pgsql_retention');

        $expired = $db->update(
            "UPDATE import_runs SET status = 'expired', payload_ciphertext = NULL, updated_at = now()
              WHERE status IN ('uploaded', 'mapped', 'validated') AND expires_at < now()"
        );
        // Belt and braces: no finished run keeps a payload (the CHECK refuses it as well).
        $db->update("UPDATE import_runs SET payload_ciphertext = NULL WHERE payload_ciphertext IS NOT NULL AND status NOT IN ('uploaded', 'mapped', 'validated', 'committing')");
        $deleted = $db->delete(
            "DELETE FROM import_runs WHERE created_at < now() - make_interval(days => ?) AND status <> 'committing'",
            [max(1, (int) config('majas.import.keep_days', 30))],
        );

        $this->info("Expired {$expired} import runs, deleted {$deleted}.");

        return self::SUCCESS;
    }
}
