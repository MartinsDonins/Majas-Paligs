<?php

declare(strict_types=1);

namespace App\Console\Commands\Prune;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * prune:login-tokens — login/step-up tokens are kept 1 day (PLANNING §5); expired trusted devices ("Uzticēties šai
 * ierīcei 30 dienas", ADR 0006) are deleted the day after they expire. Runs as majas_retention.
 */
#[AsCommand(name: 'prune:login-tokens')]
final class PruneLoginTokens extends Command
{
    protected $signature = 'prune:login-tokens';

    protected $description = 'Delete login tokens older than one day and expired trusted devices.';

    public function handle(): int
    {
        $count = DB::connection('pgsql_retention')->delete("DELETE FROM login_tokens WHERE created_at < now() - interval '1 day'");
        $devices = DB::connection('pgsql_retention')->delete("DELETE FROM trusted_devices WHERE expires_at < now() - interval '1 day'");
        $this->info("Pruned {$count} login tokens and {$devices} expired trusted devices.");

        return self::SUCCESS;
    }
}
