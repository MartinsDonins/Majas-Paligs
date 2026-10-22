<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * ops:heartbeat — every minute from the scheduler (routes/console.php). Writes the scheduler heartbeat that the MCP
 * `app_status` tool reads ("scheduler alive?"). No personal data. The error log (app_error_events) is pruned by
 * prune:error-events as majas_retention, not here: majas_app may not delete it.
 */
#[AsCommand(name: 'ops:heartbeat')]
final class OpsHeartbeat extends Command
{
    public const CACHE_KEY = 'ops:scheduler:heartbeat';

    protected $signature = 'ops:heartbeat';

    protected $description = 'Record the scheduler heartbeat.';

    public function handle(): int
    {
        Cache::put(self::CACHE_KEY, now()->toIso8601String(), now()->addDay());

        return self::SUCCESS;
    }
}
