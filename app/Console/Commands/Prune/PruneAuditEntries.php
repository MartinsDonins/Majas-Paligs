<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:audit — retention 24 months (PLANNING §5). Runs as majas_retention (`pgsql_retention`): per chain, in
 * id order, writes an ANCHOR (hash of the last pruned row, verified by trigger) and then deletes the rows up to
 * it in the same transaction, so the chain stays verifiable from the anchor.
 */
#[AsCommand(name: 'prune:audit')]
final class PruneAuditEntries extends Command
{
    protected $signature = 'prune:audit {--months=24}';

    protected $description = 'Prune audit entries older than the retention window (anchor first).';

    public function handle(): int
    {
        $db = DB::connection('pgsql_retention');
        $months = max(24, (int) $this->option('months'));
        $total = 0;

        foreach ($db->select('SELECT DISTINCT chain_key FROM audit_entries ORDER BY chain_key') as $row) {
            $chain = (int) $row->chain_key;

            $total += (int) $db->transaction(function () use ($db, $chain, $months): int {
                $last = $db->selectOne(
                    'SELECT id, hash FROM audit_entries WHERE chain_key = ? AND created_at < now() - make_interval(months => ?) ORDER BY id DESC LIMIT 1',
                    [$chain, $months],
                );

                if ($last === null) {
                    return 0;
                }

                $db->insert(
                    'INSERT INTO audit_chain_anchors (chain_key, pruned_through_id, anchor_hash) SELECT chain_key, id, hash FROM audit_entries WHERE id = ?',
                    [$last->id],
                );

                return $db->delete('DELETE FROM audit_entries WHERE chain_key = ? AND id <= ?', [$chain, $last->id]);
            });
        }

        $this->info("Pruned {$total} audit entries.");

        return self::SUCCESS;
    }
}
