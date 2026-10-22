<?php

declare(strict_types=1);

namespace App\Support\ErrorLog;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read side of app_error_events (OPS-001) for operators and the platform MCP tool.
 *
 * Rows are scrubbed again on the way out (defence in depth: a row written before a scrubber rule existed never leaves
 * this class unscrubbed). The shape is part of the MCP contract — add keys, never rename or remove them.
 */
final class ErrorLogReader
{
    public const MAX_LIMIT = 200;

    /**
     * The most recently seen error events, newest first. $limit is clamped to 1..MAX_LIMIT.
     *
     * @return list<array{fingerprint: string, exception_class: string, message: string, file: string, line: int, location: string, count: int, first_seen: string, last_seen: string}>
     */
    public static function recent(int $limit = 50): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $rows = DB::table('app_error_events')
            ->select(['fingerprint', 'exception_class', 'message', 'file', 'line', 'count', 'first_seen', 'last_seen'])
            ->orderByDesc('last_seen')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(static function (object $row): array {
            $file = (string) $row->file;
            $line = (int) $row->line;

            return [
                'fingerprint' => (string) $row->fingerprint,
                'exception_class' => (string) $row->exception_class,
                'message' => ErrorLogScrubber::scrub((string) $row->message),
                'file' => $file,
                'line' => $line,
                'location' => $file.':'.$line,
                'count' => (int) $row->count,
                'first_seen' => CarbonImmutable::parse((string) $row->first_seen)->utc()->toIso8601String(),
                'last_seen' => CarbonImmutable::parse((string) $row->last_seen)->utc()->toIso8601String(),
            ];
        })->values()->all();
    }
}
