<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Console\Commands\OpsHeartbeat;
use App\Mcp\McpAccess;
use App\Models\ErrorEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('app_status')]
#[Description('Health of the running app: version, environment, pending migrations, queue / Horizon state, scheduler heartbeat, failed jobs and the most recent error summaries (scrubbed, no personal data).')]
#[IsReadOnly]
final class AppStatusTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'errors' => $schema->integer()->description('How many recent error fingerprints to return (default 10, max 50).')->min(0)->max(50),
        ];
    }

    protected function execute(Request $request, User $actor): array
    {
        $errors = $this->intArg($request, 'errors', 10, 0, 50);
        $token = app(McpAccess::class)->token();

        return [
            'version' => (string) config('app.version'),
            'environment' => (string) app()->environment(),
            'caller' => [
                'user_id' => (int) $actor->id,
                'token_id' => $token?->id,
                'scopes' => $token !== null ? $token->scopes : [],
                'token_expires_at' => $token?->expires_at?->toIso8601String(),
            ],
            'database' => $this->database(),
            'migrations' => $this->migrations(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'failed_jobs' => $this->failedJobs(),
            'errors' => [
                'last_24h' => ErrorEvent::query()->where('last_seen_at', '>=', now()->subDay())->count(),
                'recent' => ErrorEvent::query()->orderByDesc('last_seen_at')->limit($errors)->get()->map(static fn (ErrorEvent $e): array => [
                    'exception' => $e->exception_class,
                    'location' => $e->location,
                    'summary' => $e->message,
                    'origin' => $e->origin,
                    'count' => $e->count,
                    'first_seen_at' => $e->first_seen_at->toIso8601String(),
                    'last_seen_at' => $e->last_seen_at->toIso8601String(),
                ])->values()->all(),
            ],
        ];
    }

    /**
     * @return array{ok: bool}
     */
    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true];
        } catch (Throwable) {
            return ['ok' => false];
        }
    }

    /**
     * @return array{ran: int, pending: list<string>}
     */
    private function migrations(): array
    {
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return ['ran' => count(array_intersect($files, $ran)), 'pending' => array_values(array_diff($files, $ran))];
    }

    /**
     * @return array{connection: string, pending_jobs: ?int, horizon: string}
     */
    private function queue(): array
    {
        $connection = (string) config('queue.default');

        try {
            $size = Queue::connection($connection)->size();
        } catch (Throwable) {
            $size = null;
        }

        try {
            $masters = app(MasterSupervisorRepository::class)->all();
            $horizon = match (true) {
                $masters === [] => 'inactive',
                collect($masters)->every(static fn (object $m): bool => ($m->status ?? '') === 'paused') => 'paused',
                default => 'running',
            };
        } catch (Throwable) {
            $horizon = 'unknown';
        }

        return ['connection' => $connection, 'pending_jobs' => $size, 'horizon' => $horizon];
    }

    /**
     * @return array{last_beat_at: ?string, age_seconds: ?int, healthy: bool}
     */
    private function scheduler(): array
    {
        try {
            $beat = Cache::get(OpsHeartbeat::CACHE_KEY);
        } catch (Throwable) {
            $beat = null;
        }

        if (! is_string($beat)) {
            return ['last_beat_at' => null, 'age_seconds' => null, 'healthy' => false];
        }

        $at = CarbonImmutable::parse($beat);
        $age = (int) max(0, $at->diffInSeconds(now()));

        return ['last_beat_at' => $at->toIso8601String(), 'age_seconds' => $age, 'healthy' => $age <= 180];
    }

    /**
     * @return array{total: int, last_24h: int, recent: list<array{job: string, queue: string, exception: string, failed_at: string}>}
     */
    private function failedJobs(): array
    {
        $recent = DB::table('failed_jobs')->orderByDesc('failed_at')->limit(5)->get(['payload', 'queue', 'exception', 'failed_at'])
            ->map(static function (object $row): array {
                $payload = json_decode((string) $row->payload, true);
                $job = is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'unknown';
                // Only the exception CLASS (first token before ':'); the message may hold data.
                $exception = (string) strtok((string) $row->exception, ":\n");

                return [
                    'job' => mb_substr($job, 0, 120),
                    'queue' => (string) $row->queue,
                    'exception' => mb_substr(trim($exception), 0, 120),
                    'failed_at' => CarbonImmutable::parse((string) $row->failed_at)->toIso8601String(),
                ];
            })->values()->all();

        return [
            'total' => DB::table('failed_jobs')->count(),
            'last_24h' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
            'recent' => $recent,
        ];
    }
}
