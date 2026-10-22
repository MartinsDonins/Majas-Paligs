<?php

declare(strict_types=1);

use App\Console\Commands\OpsHeartbeat;
use App\Models\ErrorEvent;
use App\Support\ErrorEventRecorder;
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

it('records reported exceptions as scrubbed fingerprints with a count', function (): void {
    $e = new RuntimeException('Mail to janis.berzins@example.test failed for apartment 145, phone +371 2'.'0000000'.', token "abc123" ?t=SECRET');

    report($e);
    report($e);

    $row = ErrorEvent::query()->sole();

    expect($row->count)->toBe(2)
        ->and($row->exception_class)->toBe(RuntimeException::class)
        ->and($row->location)->toStartWith('tests/Feature/Mcp/McpAppStatusTest.php:')
        ->and($row->message)->not->toContain('janis')
        ->and($row->message)->not->toContain('2'.'0000000')
        ->and($row->message)->not->toContain('abc123')
        ->and($row->message)->not->toContain('SECRET')
        ->and($row->message)->toContain('[email]');

    $status = mcpTool($this->token, 'app_status');
    expect($status['data']['errors']['last_24h'])->toBe(1)
        ->and($status['data']['errors']['recent'][0]['count'])->toBe(2);
});

it('does not record what is never reported (404, validation)', function (): void {
    $this->get('/no-such-page-mcp')->assertNotFound();

    expect(ErrorEvent::query()->count())->toBe(0);
});

it('never fails the request when the error log itself cannot be written', function (): void {
    DB::statement('ALTER TABLE error_events RENAME TO error_events_moved');

    try {
        ErrorEventRecorder::record(new RuntimeException('boom'));
        // The surrounding (test) transaction is still usable.
        expect(DB::selectOne('select 1 AS one')->one)->toBe(1);
    } finally {
        DB::statement('ALTER TABLE error_events_moved RENAME TO error_events');
    }
});

it('prunes error summaries after 30 days', function (): void {
    ErrorEvent::query()->create(['fingerprint' => str_repeat('a', 64), 'exception_class' => 'X', 'location' => 'a:1', 'message' => '-', 'origin' => 'http', 'count' => 1, 'first_seen_at' => now()->subDays(40), 'last_seen_at' => now()->subDays(31)]);
    ErrorEvent::query()->create(['fingerprint' => str_repeat('b', 64), 'exception_class' => 'Y', 'location' => 'b:1', 'message' => '-', 'origin' => 'http', 'count' => 1, 'first_seen_at' => now()->subDays(2), 'last_seen_at' => now()->subDay()]);

    $this->artisan('ops:heartbeat')->assertSuccessful();

    expect(ErrorEvent::query()->pluck('exception_class')->all())->toBe(['Y']);
});

it('schedules the heartbeat every minute', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'ops:heartbeat'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
});
