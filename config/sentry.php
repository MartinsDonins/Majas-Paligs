<?php

declare(strict_types=1);

use App\Support\SentryScrubber;

/*
 * Sentry SDK → self-hosted GlitchTip (EU). No PII, scrubbed URLs/breadcrumbs (MVP-SPEC A.6a).
 */
return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    'release' => trim((string) @file_get_contents(base_path('VERSION'))) ?: null,

    'environment' => env('APP_ENV', 'production'),

    'sample_rate' => 1.0,

    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    'send_default_pii' => false,

    'before_send' => [SentryScrubber::class, 'beforeSend'],

    'before_breadcrumb' => [SentryScrubber::class, 'beforeBreadcrumb'],

    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => false,
    ],

    'tracing' => [
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => false,
        'cache' => false,
        'redis_commands' => false,
        'notifications' => false,
        'missing_routes' => false,
        'continue_after_response' => true,
        'default_integrations' => true,
    ],
];
