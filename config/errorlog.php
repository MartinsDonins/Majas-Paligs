<?php

declare(strict_types=1);

/*
 * Lightweight production error log (OPS-001): App\Support\ErrorLog\ErrorLogRecorder → app_error_events, read by
 * App\Support\ErrorLog\ErrorLogReader. No personal data is stored (see ErrorLogScrubber).
 */
return [
    'enabled' => (bool) env('ERROR_LOG_ENABLED', true),

    // One database write per fingerprint per window; occurrences in between are counted in the cache.
    'throttle_seconds' => (int) env('ERROR_LOG_THROTTLE_SECONDS', 60),

    // Hard cap across all fingerprints, so an error storm never turns into a database storm.
    'max_writes_per_minute' => (int) env('ERROR_LOG_MAX_WRITES_PER_MINUTE', 60),

    // Cache store for the throttle counters (null = the default store).
    'cache_store' => env('ERROR_LOG_CACHE_STORE'),

    // Retention: prune:error-events (daily) deletes rows not seen for 30 days.
];
