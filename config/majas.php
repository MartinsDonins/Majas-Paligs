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
        // Resident roster (SEC-011, ROUND2-SPEC §5.2): ContactVault + blind indexes. Never reuse the plate keys.
        'contact_enc' => env('CONTACT_ENC_KEY'),
        'contact_index' => env('CONTACT_INDEX_KEY'),
        // Only while rotating the roster encryption master (contacts:rekey re-seals every building, then remove it).
        'contact_enc_previous' => env('CONTACT_ENC_KEY_PREVIOUS'),
    ],

    /* Working host and future own domain (BUSINESS §6). */
    'public_host' => env('MAJAS_PUBLIC_HOST', 'kaimini.coredigify.com'),

    'platform_alert_email' => env('PLATFORM_ALERT_EMAIL'),

    /*
     * The processor named in the house documents (ROUND2-SPEC §3.4 placeholders {processor.*}, {subprocessors.list}).
     * MANUAL-TODO: the legal entity, notice address and the sub-processor list are confirmed by the owner before the
     * pilot signs a DPA; until then the documents show the "to be completed" line below.
     */
    'processor' => [
        'name' => env('MAJAS_PROCESSOR_NAME', 'CoreDigify'),
        'contact' => env('MAJAS_PROCESSOR_CONTACT', 'https://coredigify.com'),
        // Semicolon-separated "Name — purpose — EU country" entries.
        'subprocessors' => env('MAJAS_SUBPROCESSORS'),
    ],

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

    'session' => [
        // Idle lifetime of a session on a device with "Atcerēties šo telefonu" (App\Http\Middleware\SessionLifetime).
        // Without the remember cookie: SESSION_LIFETIME (120) and the cookie ends with the browser.
        'remembered_minutes' => (int) env('SESSION_REMEMBERED_LIFETIME', 60 * 24 * 7),
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
        // "Viesu caurlaide vienmēr vienam auto" (PARK-011): ≥ N observations on ≥ N distinct days spanning ≥ N days,
        // exactly one distinct plate (blind index — no decryption).
        'guest_same_car' => [
            'min_observations' => (int) env('PARKING_GUEST_SAME_CAR_OBSERVATIONS', 3),
            'min_days' => (int) env('PARKING_GUEST_SAME_CAR_DAYS', 3),
            'min_span_days' => (int) env('PARKING_GUEST_SAME_CAR_SPAN_DAYS', 14),
        ],
        // Guest-permit observations are kept 6 months (prune:permit-observations), like plate lookups.
        'observation_retention_months' => 6,
    ],

    /* Round 2 — CSV import pipeline (DB-003, ROUND2-SPEC §4). Kinds map to their ImportHandler. */
    'import' => [
        'handlers' => [
            'roster' => 'App\Services\Roster\RosterImportHandler',
            // PARK-012 (resolved only once the class exists).
            'permits' => 'App\Services\Parking\PermitImportHandler',
        ],
        'max_bytes' => 2 * 1024 * 1024,
        'max_rows' => 2000,
        'max_columns' => 30,
        'max_cell_chars' => 1000,
        'resume_hours' => 24,
        'dry_run_valid_minutes' => 30,
        'per_day' => 10,
        'chunk' => 100,
        'preview_page' => 50,
        'keep_days' => 30,
    ],

    /* Round 2 — resident roster (ADMIN-007, ROUND2-SPEC §5). */
    'roster' => [
        'reveal_per_day' => 100,
        'reveal_minutes' => 5,
        'export_per_day' => 5,
        'list_audit_minutes' => 10,
        'moved_out_keep_days' => 30,
        'review_reminder_months' => 24,
        'max_phones' => 3,
        'name_max' => 120,
        'comment_max' => 280,
    ],

    /* Round 3 — the in-app MCP server (docs/MCP.md, ADR 0008). */
    'mcp' => [
        'per_ip_per_minute' => 120,
        'per_token_per_minute' => 60,
    ],
];
