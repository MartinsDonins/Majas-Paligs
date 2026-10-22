<?php

declare(strict_types=1);

use App\Console\Commands\OpsHeartbeat;
use App\Support\ErrorLog\ErrorLogRecorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * app_status (round 3): version, migrations, queue, scheduler heartbeat, failed jobs and the PII-free error log.
 */

beforeEach(function (): void {
    $this->admin = mcpAdmin();
    $this->token = mcpToken($this->admin);
});

it('reports version, migrations, scheduler, failed jobs and the caller', function (): void {
    $this->artisan('ops:heartbeat')->assertSuccessful();
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob']),
        'exception' => "RuntimeException: mail to someone@example.test failed\n#0 trace",
        'failed_at' => now(),
    ]);

    $result = mcpTool($this->token, 'app_status');

    expect($result['isError'])->toBeFalse($result['text'])
        ->and($result['data']['version'])->toBe((string) config('app.version'))
        ->and($result['data']['migrations']['pending'])->toBe([])
        ->and($result['data']['migrations']['ran'])->toBeGreaterThan(10)
        ->and($result['data']['scheduler']['healthy'])->toBeTrue()
        ->and($result['data']['failed_jobs']['total'])->toBe(1)
        ->and($result['data']['failed_jobs']['recent'][0])->toMatchArray(['job' => 'App\\Jobs\\ExampleJob', 'exception' => 'RuntimeException'])
        ->and($result['data']['caller'])->toMatchArray(['user_id' => $this->admin->id, 'token_id' => mcpTokenId($this->token), 'scopes' => ['read']])
        ->and($result['raw'])->not->toContain('someone@example.test');
});

it('reports a missing scheduler heartbeat as unhealthy', function (): void {
    Cache::forget(OpsHeartbeat::CACHE_KEY);

    expect(mcpTool($this->token, 'app_status')['data']['scheduler'])->toBe(['last_beat_at' => null, 'age_seconds' => null, 'healthy' => false]);
});

it('serves reported exceptions through the error-log reader, scrubbed, with origin and count', function (): void {
    config(['errorlog.enabled' => true]);
    $e = new RuntimeException('Mail to janis.berzins@example.test failed for apartment 145, phone +371 2'.'0000000'.', token "abc123" ?t=SECRET');

    report($e);

    $status = mcpTool($this->token, 'app_status');
    $recent = $status['data']['errors']['recent'];

    expect($status['isError'])->toBeFalse($status['text'])
        ->and($status['data']['errors']['last_24h'])->toBe(1)
        ->and($recent)->toHaveCount(1)
        ->and(array_keys($recent[0]))->toBe(['exception', 'location', 'summary', 'origin', 'count', 'first_seen_at', 'last_seen_at'])
        ->and($recent[0])->toMatchArray(['exception' => RuntimeException::class, 'origin' => 'http', 'count' => 1])
        ->and($recent[0]['location'])->toStartWith('tests/Feature/Mcp/McpAppStatusTest.php:')
        ->and($recent[0]['summary'])->toContain('[email]')
        ->and($status['raw'])->not->toContain('janis')
        ->and($status['raw'])->not->toContain('2'.'0000000')
        ->and($status['raw'])->not->toContain('abc123')
        ->and($status['raw'])->not->toContain('SECRET');

    expect(mcpTool($this->token, 'app_status', ['errors' => 0])['data']['errors']['recent'])->toBe([]);
});

it('never fails the request when the error log table itself cannot be written', function (): void {
    config(['errorlog.enabled' => true]);
    DB::statement('ALTER TABLE app_error_events RENAME TO app_error_events_moved');

    try {
        ErrorLogRecorder::record(new RuntimeException('boom'));
        // The surrounding (test) transaction is still usable.
        expect(DB::selectOne('select 1 AS one')->one)->toBe(1);
    } finally {
        DB::statement('ALTER TABLE app_error_events_moved RENAME TO app_error_events');
    }
});

it('schedules the heartbeat every minute', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'ops:heartbeat'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
});
