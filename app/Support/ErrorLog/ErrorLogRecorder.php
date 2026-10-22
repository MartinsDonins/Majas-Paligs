<?php

declare(strict_types=1);

namespace App\Support\ErrorLog;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Writes reported exceptions into app_error_events (OPS-001), called from the exception handler's report callback
 * (bootstrap/app.php) — so only exceptions Laravel reports (no 404s, validation or auth failures).
 *
 * Stored: fingerprint, exception class, scrubbed + truncated message (ErrorLogScrubber), file:line of the first
 * frame in the application's own code, origin (http / console / queue / mcp), count, first/last seen. Never stored:
 * request data, URL, headers, user, building, stack trace. Read back only through ErrorLogReader (MCP `app_status`).
 *
 * Throttling (an error storm must never become a database storm):
 * - per fingerprint: at most one write per `errorlog.throttle_seconds`; occurrences inside the window are counted in
 *   the cache and added to `count` by the next write of that fingerprint (so `count` lags by at most one window);
 * - globally: at most `errorlog.max_writes_per_minute` writes per minute across all fingerprints.
 * Recording never throws and never re-enters itself: a failure here (database down, cache down) is swallowed so the
 * original exception still reaches the log and GlitchTip.
 */
final class ErrorLogRecorder
{
    private static bool $recording = false;

    public static function record(Throwable $e): void
    {
        if (self::$recording || ! (bool) config('errorlog.enabled', true)) {
            return;
        }

        self::$recording = true;
        try {
            self::write($e);
        } catch (Throwable) {
            // Best effort by design (see class doc).
        } finally {
            self::$recording = false;
        }
    }

    /**
     * Stable identity of a failure: class + location + message shape (digits collapsed), never raw values.
     */
    public static function fingerprint(string $class, string $file, int $line, string $scrubbedMessage): string
    {
        return hash('sha256', $class.'|'.$file.':'.$line.'|'.preg_replace('/\d+/', '0', $scrubbedMessage));
    }

    /**
     * The frame closest to the throw site that is the project's own code (anything under the project root except
     * vendor/ and compiled views), else the throw site itself; the path is relative to the project root. A
     * QueryException thrown inside the framework is located at the app line that ran the query.
     *
     * @return array{0: string, 1: int}
     */
    public static function location(Throwable $e): array
    {
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        foreach ($frames as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if ($file === '' || ! str_starts_with($file, $base)) {
                continue;
            }
            $relative = substr($file, strlen($base));
            if (! str_starts_with($relative, 'vendor/') && ! str_starts_with($relative, 'storage/')) {
                return [mb_substr($relative, -255), (int) ($frame['line'] ?? 0)];
            }
        }

        $file = str_replace('\\', '/', $e->getFile());

        return [mb_substr(str_starts_with($file, $base) ? substr($file, strlen($base)) : basename($file), -255), $e->getLine()];
    }

    private static function write(Throwable $e): void
    {
        $class = mb_substr($e::class, 0, 255);
        $message = ErrorLogScrubber::scrub($e->getMessage());
        [$file, $line] = self::location($e);
        $fingerprint = self::fingerprint($class, $file, $line, $message);

        $pendingKey = "errorlog:pending:{$fingerprint}";
        $window = max(1, (int) config('errorlog.throttle_seconds', 60));
        $cache = Cache::store(config('errorlog.cache_store'));

        $cache->add($pendingKey, 0, 86_400);
        $cache->increment($pendingKey);

        if (! $cache->add("errorlog:window:{$fingerprint}", 1, $window)) {
            return; // counted; written with the next write of this fingerprint
        }
        if (RateLimiter::tooManyAttempts('errorlog:writes', (int) config('errorlog.max_writes_per_minute', 60))) {
            return;
        }
        RateLimiter::hit('errorlog:writes', 60);

        $count = max(1, (int) $cache->pull($pendingKey, 1));
        $origin = self::origin();

        // A savepoint when called inside an open transaction: a failed insert must not abort the caller's work.
        DB::transaction(static function () use ($fingerprint, $class, $message, $file, $line, $origin, $count): void {
            DB::statement(
                'INSERT INTO app_error_events (fingerprint, exception_class, message, file, line, origin, count, first_seen, last_seen)
                 VALUES (?, ?, ?, ?, ?, ?, ?, now(), now())
                 ON CONFLICT (fingerprint) DO UPDATE
                 SET count = app_error_events.count + EXCLUDED.count, message = EXCLUDED.message,
                     origin = EXCLUDED.origin, last_seen = EXCLUDED.last_seen',
                [$fingerprint, $class, $message, $file, $line, $origin, $count],
            );
        });
    }

    /**
     * Where the failure happened (the most recent occurrence wins): an MCP call, a queue worker, another console
     * command, or an ordinary HTTP request. One of the app_error_events_origin_check values.
     */
    public static function origin(): string
    {
        if (app()->bound('mcp.request') || (app()->bound('request') && request()->is('mcp'))) {
            return 'mcp';
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return app()->runningConsoleCommand('horizon', 'horizon:work', 'queue:work') ? 'queue' : 'console';
        }

        return 'http';
    }
}
