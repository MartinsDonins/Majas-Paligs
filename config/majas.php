<?php

declare(strict_types=1);

/*
 * Mājas Palīgs domain configuration. Secrets come from the environment only (.env / Coolify).
 */
return [

    /*
     * Dedicated keys (PLANNING §5 A04) — never derived from APP_KEY.
     * Format: "base64:<32 bytes>". Generate with `php artisan majas:keys`.
     */
    'keys' => [
        'invite' => env('INVITE_KEY'),
        'plate_enc' => env('PLATE_ENC_KEY'),
        'plate_index' => env('PLATE_INDEX_KEY'),
    ],

    /* Working host and future own domain (BUSINESS §6). */
    'public_host' => env('MAJAS_PUBLIC_HOST', 'kaimini.coredigify.com'),

    'platform_alert_email' => env('PLATFORM_ALERT_EMAIL'),

    'invites' => [
        'alphabet' => '23456789ABCDEFGHJKMNPQRSTUVWXYZ',
        'length' => 10,
        'max_uses' => 3,
        'valid_days' => 60,
        'throttle' => [
            'ip_per_15min' => 5,
            'building_per_hour' => 20,
            'global_per_hour' => 200,
        ],
    ],

    'magic_link' => [
        'ttl_minutes' => 15,
        'code_attempts' => 5,
        'per_email_15min' => 3,
        'per_ip_hour' => 10,
    ],

    'pin' => [
        'handle_alphabet' => '23456789ABCDEFGHJKMNPQRSTUVWXYZ',
        'handle_suffix_length' => 4,
        'handle_cooldown_after' => 5,
        'handle_cooldown_minutes' => 15,
        'handle_daily_lock_after' => 10,
        'ip_per_hour' => 20,
        'building_per_hour' => 100,
        'building_pause_minutes' => 15,
        'global_failed_alert_per_hour' => 1000,
        'global_unknown_alert_per_hour' => 300,
        'temp_code_length' => 8,
        'temp_code_hours' => 24,
        'elder_resets_per_day' => 5,
        // Argon2id cost (PHP defaults in production; cheap only in the test suite).
        'argon' => [
            'memory_cost' => (int) env('MAJAS_ARGON_MEMORY', 65536),
            'time_cost' => (int) env('MAJAS_ARGON_TIME', 4),
        ],
    ],

    'step_up' => [
        'valid_minutes' => 120,
        'code_ttl_minutes' => 10,
    ],

    'roles' => [
        'valid_months' => 12,
    ],

    'audit' => [
        'retention_months' => 24,
    ],

    'exports' => [
        'signed_minutes' => 10,
        'keep_hours' => 24,
        'disk' => 'local',
    ],

    'livewire_throttle_per_minute' => 120,
];
