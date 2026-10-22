<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lightweight, PII-free error log for the MCP `app_status` tool (round 3, docs/MCP.md). Called from the exception
 * handler's report callback for every REPORTED exception (404s, validation errors and the other `dontReport` types
 * never get here). One row per fingerprint = sha256(class | file | line); a repeat only bumps `count` and
 * `last_seen_at`.
 *
 * Privacy: the message is scrubbed before it is stored — e-mail addresses, quoted values, long digit runs (phones,
 * ids, codes), hex / base64-looking tokens and anything after a `?` are replaced — and clipped to one 160-character
 * line. No request data, no user, no IP. GlitchTip keeps the full event (its own scrubber, 30 days).
 *
 * Never throws: a broken database must not turn one error into two (the original exception is still logged).
 */
final class ErrorEventRecorder
{
    private static bool $recording = false;

    public static function record(Throwable $e): void
    {
        if (self::$recording) {
            return;
        }

        self::$recording = true;

        try {
            $class = mb_substr($e::class, 0, 200);
            $location = mb_substr(self::relativePath($e->getFile()).':'.$e->getLine(), 0, 200);
            $fingerprint = hash('sha256', $class.'|'.$location);
            $now = now();

            // Inside an open transaction: a savepoint, so a failed insert can never poison the caller's transaction.
            DB::transaction(static fn () => DB::table('error_events')->upsert(
                [[
                    'fingerprint' => $fingerprint,
                    'exception_class' => $class,
                    'location' => $location,
                    'message' => self::scrub($e->getMessage()),
                    'origin' => self::origin(),
                    'count' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]],
                ['fingerprint'],
                [
                    'count' => DB::raw('error_events.count + 1'),
                    'last_seen_at' => DB::raw('excluded.last_seen_at'),
                    'message' => DB::raw('excluded.message'),
                ],
            ));
        } catch (Throwable) {
            // The database may be the problem; the default log channel still has the exception.
        } finally {
            self::$recording = false;
        }
    }

    /**
     * One scrubbed line: no e-mails, quoted values, long digit runs, token-like strings or query strings.
     */
    public static function scrub(string $message): string
    {
        $line = (string) preg_replace('/\s+/u', ' ', $message);
        $line = (string) preg_replace('/\?\S*/u', '?…', $line);
        $line = (string) preg_replace('/[^\s@"\'<>]+@[^\s@"\'<>]+/u', '[email]', $line);
        $line = (string) preg_replace('/"[^"]*"|\'[^\']*\'|`[^`]*`/u', '[value]', $line);
        $line = (string) preg_replace('/\b[A-Za-z0-9+\/_=-]{24,}\b/u', '[token]', $line);
        $line = (string) preg_replace('/\+?\d[\d\s().-]{3,}\d/u', '[n]', $line);

        $line = trim($line);

        return mb_substr($line === '' ? '-' : $line, 0, 160);
    }

    private static function relativePath(string $file): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : basename($file);
    }

    private static function origin(): string
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
