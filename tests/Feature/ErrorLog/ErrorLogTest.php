<?php

declare(strict_types=1);

use App\Support\ErrorLog\ErrorLogReader;
use App\Support\ErrorLog\ErrorLogRecorder;
use App\Support\ErrorLog\ErrorLogScrubber;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\DbRoles;

/*
 * OPS-001 — operator error log (app_error_events): scrubbed, throttled, no personal data, 30-day retention, and the
 * ErrorLogReader::recent() contract the platform MCP tool relies on. Synthetic values only.
 */
beforeEach(function (): void {
    config(['errorlog.enabled' => true, 'errorlog.throttle_seconds' => 60, 'errorlog.max_writes_per_minute' => 60]);
});

function errorRows(): array
{
    return DB::table('app_error_events')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
}

function randomFingerprint(): string
{
    return hash('sha256', (string) random_int(0, PHP_INT_MAX).microtime());
}

it('scrubs personal data out of exception messages', function (string $message, array $absent, array $present): void {
    $scrubbed = ErrorLogScrubber::scrub($message);

    foreach ($absent as $needle) {
        expect($scrubbed)->not->toContain($needle);
    }
    foreach ($present as $needle) {
        expect($scrubbed)->toContain($needle);
    }
})->with([
    'e-mail' => ['No user for anna.berzina+test@example.test', ['anna', 'example.test'], ['[email]']],
    'LV mobile' => ['Cannot text 26123456 or +371 26 123 456', ['26123456', '26 123 456'], ['[phone]']],
    'international phone' => ['SMS to +44 20 7946 0958 failed', ['7946'], ['[phone]']],
    'plate' => ['Permit clash for AB-1234 and EV 123', ['AB-1234', 'EV 123'], ['[plate]']],
    'cyrillic plate' => ['Lookup АВ1234 failed', ['АВ1234'], ['[plate]']],
    'lower-case plate' => ['Lookup ab 1234 failed', ['ab 1234'], ['[plate]']],
    'quoted name' => ['Resident "Jānis Bērziņš" not found', ['Jānis', 'Bērziņš'], ['"[redacted]"']],
    'IP' => ['Rate limit for 192.0.2.17', ['192.0.2.17'], ['[ip]']],
    'token' => ['Bad signature 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08', ['9f86d081'], ['[token]']],
    'URL query' => ['GET https://kaimini.example/join?c=ABCD2345EF failed', ['ABCD2345EF', '?c='], ['https://kaimini.example/join']],
    'SQL bindings' => [
        "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(anna@example.test) already exists. (Connection: pgsql, SQL: insert into \"users\" (\"email\", \"name\") values (anna@example.test, Anna Bērziņa))",
        ['anna', 'Bērziņa', 'insert into'],
        ['SQLSTATE[23505]', 'users_email_unique', 'Key (email)=([redacted])'],
    ],
    'keeps identifiers' => ['Undefined array key "heating.report.cta" in App\Models\User', [], ['heating.report.cta', 'App\Models\User']],
]);

it('keeps identifiers and caps the length', function (): void {
    expect(ErrorLogScrubber::scrub('Column "plate_index" missing, encoding utf-8, line 42'))->toBe('Column "plate_index" missing, encoding utf-8, line 42')
        ->and(mb_strlen(ErrorLogScrubber::scrub(str_repeat('x ', 600))))->toBe(ErrorLogScrubber::MAX_LENGTH);
});

it('records an exception from the real exception handler without request data', function (): void {
    Route::get('/__ops001-boom', static function (): never {
        throw new RuntimeException('Import failed for anna@example.test, phone +371 26123456, plate AB-1234');
    })->middleware('web');

    $this->get('/__ops001-boom?email=leak%40example.test&note=SECRETNOTE')->assertStatus(500);

    $rows = errorRows();
    expect($rows)->toHaveCount(1);
    $row = $rows[0];
    $all = json_encode($row, JSON_UNESCAPED_UNICODE);

    expect($row['exception_class'])->toBe(RuntimeException::class)
        ->and($row['message'])->toBe('Import failed for [email], phone [phone], plate [plate]')
        ->and($row['file'])->toBe('tests/Feature/ErrorLog/ErrorLogTest.php')
        ->and((int) $row['line'])->toBeGreaterThan(0)
        ->and((int) $row['count'])->toBe(1)
        ->and($row['fingerprint'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($all)->not->toContain('anna')->not->toContain('leak')->not->toContain('SECRETNOTE')->not->toContain('__ops001');
});

it('does not record what Laravel does not report (a 404)', function (): void {
    $this->get('/__no-such-page-ops001')->assertNotFound();

    expect(errorRows())->toBe([]);
});

it('throttles per fingerprint and carries the counted occurrences into the next write', function (): void {
    $boom = static fn () => new LogicException('Riser 7 has no rooms');

    for ($i = 0; $i < 5; $i++) {
        report($boom());
    }
    expect(errorRows())->toHaveCount(1)->and((int) errorRows()[0]['count'])->toBe(1);

    $this->travel(61)->seconds();
    report($boom());

    expect(errorRows())->toHaveCount(1)->and((int) errorRows()[0]['count'])->toBe(6);
});

it('caps database writes per minute across all fingerprints', function (): void {
    config(['errorlog.max_writes_per_minute' => 2]);

    report(new RuntimeException('first'));
    report(new LogicException('second'));
    report(new DomainException('third'));

    expect(errorRows())->toHaveCount(2);
});

it('never throws from the recorder when its cache store is broken', function (): void {
    config(['errorlog.cache_store' => 'no-such-store']);

    ErrorLogRecorder::record(new RuntimeException('cache store is broken'));

    expect(errorRows())->toBe([]);
});

it('is switched off by ERROR_LOG_ENABLED=false', function (): void {
    config(['errorlog.enabled' => false]);

    report(new RuntimeException('off'));

    expect(errorRows())->toBe([]);
});

it('reads recent rows newest first, clamped, re-scrubbed, in the MCP contract shape', function (): void {
    $insert = fn (string $message, string $lastSeen) => DB::table('app_error_events')->insert([
        'fingerprint' => randomFingerprint(), 'exception_class' => RuntimeException::class, 'message' => $message,
        'file' => 'app/Services/Example.php', 'line' => 12, 'count' => 3, 'first_seen' => '2026-10-01 10:00:00+00', 'last_seen' => $lastSeen,
    ]);
    $insert('older', '2026-10-20 10:00:00+00');
    $insert('written before a rule existed: anna@example.test', '2026-10-21 10:00:00+00');

    $rows = ErrorLogReader::recent(10);

    expect($rows)->toHaveCount(2)
        ->and(array_keys($rows[0]))->toBe(['fingerprint', 'exception_class', 'message', 'file', 'line', 'location', 'count', 'first_seen', 'last_seen'])
        ->and($rows[0]['message'])->toBe('written before a rule existed: [email]')
        ->and($rows[0]['location'])->toBe('app/Services/Example.php:12')
        ->and($rows[0]['count'])->toBe(3)
        ->and($rows[0]['last_seen'])->toBe('2026-10-21T10:00:00+00:00')
        ->and($rows[1]['message'])->toBe('older')
        ->and(ErrorLogReader::recent(1))->toHaveCount(1)
        ->and(ErrorLogReader::recent(0))->toHaveCount(1)
        ->and(ErrorLogReader::recent(-5))->toHaveCount(1);
});

it('lets majas_app record and bump but never rewrite or delete; majas_retention prunes after 30 days', function (): void {
    $app = DbRoles::connect('majas_app');
    $owner = DbRoles::connect('majas_owner');
    $fresh = randomFingerprint();
    $stale = randomFingerprint();

    try {
        $upsert = 'INSERT INTO app_error_events (fingerprint, exception_class, message, file, line, count, first_seen, last_seen)
                   VALUES (?, ?, ?, ?, ?, 1, now(), now())
                   ON CONFLICT (fingerprint) DO UPDATE SET count = app_error_events.count + EXCLUDED.count, message = EXCLUDED.message, last_seen = EXCLUDED.last_seen';
        $app->statement($upsert, [$fresh, 'RuntimeException', 'one', 'app/X.php', 1]);
        $app->statement($upsert, [$fresh, 'RuntimeException', 'two', 'app/X.php', 1]);
        expect((int) $owner->table('app_error_events')->where('fingerprint', $fresh)->value('count'))->toBe(2);

        expect(fn () => $app->delete('DELETE FROM app_error_events WHERE fingerprint = ?', [$fresh]))->toThrow(QueryException::class);
        expect(fn () => $app->update('UPDATE app_error_events SET exception_class = ? WHERE fingerprint = ?', ['Forged', $fresh]))->toThrow(QueryException::class);

        $owner->table('app_error_events')->insert([
            'fingerprint' => $stale, 'exception_class' => 'RuntimeException', 'message' => 'stale', 'file' => 'app/X.php', 'line' => 2,
            'count' => 1, 'first_seen' => now()->subDays(40), 'last_seen' => now()->subDays(31),
        ]);

        config(['database.connections.pgsql_retention.database' => config('database.connections.pgsql.database')]);
        DB::purge('pgsql_retention');
        $this->artisan('prune:error-events')->assertSuccessful();

        expect($owner->table('app_error_events')->whereIn('fingerprint', [$fresh, $stale])->pluck('fingerprint')->all())->toBe([$fresh]);
        expect(fn () => DbRoles::connect('majas_retention')->update('UPDATE app_error_events SET count = 1 WHERE fingerprint = ?', [$fresh]))
            ->toThrow(QueryException::class);
    } finally {
        $owner->table('app_error_events')->whereIn('fingerprint', [$fresh, $stale])->delete();
    }
});

it('schedules the daily prune', function (): void {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command);

    expect($events->contains(fn (string $c) => str_contains($c, 'prune:error-events')))->toBeTrue();
});
