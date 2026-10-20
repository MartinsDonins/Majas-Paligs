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
        // Only while rotating the plate encryption master (plates:rekey re-seals every building, then remove it).
        'plate_enc_previous' => env('PLATE_ENC_KEY_PREVIOUS'),
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
            // Wrong codes only (RedeemInvite): a correct code never uses up the device's budget.
            'ip_per_15min' => 10,
            // Sized for a letter drop: a 180-apartment house can redeem a third of its letters in one evening hour.
            'building_per_hour' => 60,
            // ALERT-ONLY platform monitor (counts well-formed codes only) — never blocks /join (review fix).
            'global_alert_per_hour' => 500,
        ],
    ],

    'magic_link' => [
        'ttl_minutes' => 15,
        'code_attempts' => 5,
        'per_email_15min' => 3,
        'per_ip_hour' => 10,
        // Cumulative wrong 6-digit codes per account across tokens (24 h); then only the link works (review fix).
        'code_failures_per_day' => 10,
    ],

    'pin' => [
        'handle_alphabet' => '23456789ABCDEFGHJKMNPQRSTUVWXYZ',
        'handle_suffix_length' => 4,
        'handle_cooldown_after' => 5,
        'handle_cooldown_minutes' => 15,
        'handle_daily_lock_after' => 10,
        // Progressive lock (1st / 2nd / 3rd+ lock within 7 days); an elder reset clears it at once.
        'lock_hours' => [4, 24, 72],
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
        // Sliding: lapses after this many minutes WITHOUT a step-up-protected request (= SESSION_LIFETIME)…
        'valid_minutes' => 120,
        // …and at the latest this long after the confirmation, however active the person is.
        'max_minutes' => 720,
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

    /* Package B — heating & outages (MVP-SPEC B). */
    'heating' => [
        // Heating reports are pruned one month after this month-day (end of the heating season).
        'season_end' => '05-31',
        // Undo window shown on the thank-you screen (server accepts up to HeatingReportService::UNDO_SECONDS).
        'undo_seconds_ui' => 8,
        'poll_seconds' => 60,
    ],

    'outages' => [
        'poll_seconds' => 30,
    ],

    /* Package C — parking (MVP-SPEC C). Quotas live in buildings.settings (lookup_quota, sighting_quota). */
    'parking' => [
        // Private disk for sighting photos (building/{id}/sightings/{ulid}.jpg), served only via 5-min signed URLs.
        'photo_disk' => env('PARKING_PHOTO_DISK', 'local'),
        // Legal banner key (platform legal_notices, editable in the platform panel).
        'legal_notice_key' => 'parking.riga.current_rule',
    ],
];
