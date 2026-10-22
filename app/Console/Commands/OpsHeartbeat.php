<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ErrorEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * ops:heartbeat — every minute from the scheduler (routes/console.php). Writes the scheduler heartbeat that the MCP
 * `app_status` tool reads ("scheduler alive?") and prunes `error_events` older than 30 days. No personal data.
 */
#[AsCommand(name: 'ops:heartbeat')]
final class OpsHeartbeat extends Command
{
    public const CACHE_KEY = 'ops:scheduler:heartbeat';

    public const ERROR_RETENTION_DAYS = 30;

    protected $signature = 'ops:heartbeat';

    protected $description = 'Record the scheduler heartbeat and prune the error summary log.';

    public function handle(): int
    {
        Cache::put(self::CACHE_KEY, now()->toIso8601String(), now()->addDay());

        ErrorEvent::query()->where('last_seen_at', '<', now()->subDays(self::ERROR_RETENTION_DAYS))->delete();

        return self::SUCCESS;
    }
}
