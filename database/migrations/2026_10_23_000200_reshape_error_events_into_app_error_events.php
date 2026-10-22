<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OPS-001 — one error log (round 3 integration). 2026_10_23_000100 (deployed since 0.0.31) created `error_events` for
 * the MCP `app_status` tool; the hardening branch designed `app_error_events` with a stronger scrubber
 * (App\Support\ErrorLog\ErrorLogScrubber), per-fingerprint throttling and roles.sql-style grants. This migration
 * keeps the rows and turns the deployed table into the surviving design — 000100 itself is never edited.
 *
 * - rename error_events → app_error_events (with its sequence, indexes and constraints);
 * - first_seen_at / last_seen_at → first_seen / last_seen (default now());
 * - location ("file:line") → file + line;
 * - fingerprint char(64) → text with a sha256-hex CHECK, count → bigint (CHECK >= 1); `origin` (http / console /
 *   queue / mcp) is kept from the MCP design;
 * - grants (MVP-SPEC A.2a): majas_app INSERT + SELECT and UPDATE of (message, origin, count, last_seen) only — it
 *   records and bumps, never rewrites identity columns, never deletes; majas_retention SELECT + DELETE
 *   (prune:error-events, 30 days after last seen). ops:heartbeat no longer prunes as majas_app.
 *
 * Platform table (no building_id). No personal data: scrubbed, truncated message; no request data, URL, user,
 * building or stack trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE error_events RENAME TO app_error_events;
ALTER SEQUENCE IF EXISTS error_events_id_seq RENAME TO app_error_events_id_seq;
ALTER INDEX IF EXISTS error_events_pkey RENAME TO app_error_events_pkey;
ALTER INDEX IF EXISTS error_events_fingerprint_unique RENAME TO app_error_events_fingerprint_unique;
ALTER INDEX IF EXISTS error_events_last_seen_at_index RENAME TO app_error_events_last_seen_index;
ALTER TABLE app_error_events RENAME CONSTRAINT error_events_origin_check TO app_error_events_origin_check;
ALTER TABLE app_error_events DROP CONSTRAINT error_events_lengths_check;

ALTER TABLE app_error_events RENAME COLUMN first_seen_at TO first_seen;
ALTER TABLE app_error_events RENAME COLUMN last_seen_at TO last_seen;
ALTER TABLE app_error_events ALTER COLUMN first_seen SET DEFAULT now();
ALTER TABLE app_error_events ALTER COLUMN last_seen SET DEFAULT now();
ALTER TABLE app_error_events ALTER COLUMN fingerprint TYPE text;
ALTER TABLE app_error_events ALTER COLUMN count TYPE bigint;

ALTER TABLE app_error_events ADD COLUMN file text, ADD COLUMN line integer;
UPDATE app_error_events SET
    file = left(regexp_replace(location, ':[0-9]+$', ''), 255),
    line = COALESCE(substring(location FROM ':([0-9]+)$')::integer, 0);
ALTER TABLE app_error_events ALTER COLUMN file SET NOT NULL, ALTER COLUMN line SET NOT NULL;
ALTER TABLE app_error_events DROP COLUMN location;

ALTER TABLE app_error_events ADD CONSTRAINT app_error_events_fingerprint_check CHECK (fingerprint ~ '^[0-9a-f]{64}$');
ALTER TABLE app_error_events ADD CONSTRAINT app_error_events_class_check CHECK (char_length(exception_class) BETWEEN 1 AND 255);
ALTER TABLE app_error_events ADD CONSTRAINT app_error_events_message_check CHECK (char_length(message) <= 500);
ALTER TABLE app_error_events ADD CONSTRAINT app_error_events_file_check CHECK (char_length(file) <= 255);
ALTER TABLE app_error_events ADD CONSTRAINT app_error_events_count_check CHECK (count >= 1);
ALTER TABLE app_error_events ADD CONSTRAINT app_error_events_seen_check CHECK (last_seen >= first_seen);

-- majas_app: record and bump, never rewrite identity columns, never delete (default privileges gave full DML).
REVOKE UPDATE, DELETE, TRUNCATE ON app_error_events FROM majas_app;
GRANT UPDATE (message, origin, count, last_seen) ON app_error_events TO majas_app;

-- Retention role (prune:error-events): rows not seen for 30 days.
GRANT SELECT, DELETE ON app_error_events TO majas_retention;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
REVOKE SELECT, DELETE ON app_error_events FROM majas_retention;
REVOKE UPDATE (message, origin, count, last_seen) ON app_error_events FROM majas_app;
GRANT UPDATE, DELETE ON app_error_events TO majas_app;

ALTER TABLE app_error_events DROP CONSTRAINT app_error_events_fingerprint_check;
ALTER TABLE app_error_events DROP CONSTRAINT app_error_events_class_check;
ALTER TABLE app_error_events DROP CONSTRAINT app_error_events_message_check;
ALTER TABLE app_error_events DROP CONSTRAINT app_error_events_file_check;
ALTER TABLE app_error_events DROP CONSTRAINT app_error_events_count_check;
ALTER TABLE app_error_events DROP CONSTRAINT app_error_events_seen_check;

ALTER TABLE app_error_events ADD COLUMN location text;
UPDATE app_error_events SET
    location = left(file || ':' || line, 200),
    exception_class = left(exception_class, 200),
    message = left(message, 160);
ALTER TABLE app_error_events ALTER COLUMN location SET NOT NULL;
ALTER TABLE app_error_events DROP COLUMN file, DROP COLUMN line;

ALTER TABLE app_error_events ALTER COLUMN count TYPE integer;
ALTER TABLE app_error_events ALTER COLUMN fingerprint TYPE char(64);
ALTER TABLE app_error_events ALTER COLUMN first_seen DROP DEFAULT;
ALTER TABLE app_error_events ALTER COLUMN last_seen DROP DEFAULT;
ALTER TABLE app_error_events RENAME COLUMN first_seen TO first_seen_at;
ALTER TABLE app_error_events RENAME COLUMN last_seen TO last_seen_at;

ALTER TABLE app_error_events ADD CONSTRAINT error_events_lengths_check CHECK (
    char_length(exception_class) <= 200 AND char_length(location) <= 200 AND char_length(message) <= 200 AND char_length(origin) <= 120
);
ALTER TABLE app_error_events RENAME CONSTRAINT app_error_events_origin_check TO error_events_origin_check;
ALTER INDEX IF EXISTS app_error_events_last_seen_index RENAME TO error_events_last_seen_at_index;
ALTER INDEX IF EXISTS app_error_events_fingerprint_unique RENAME TO error_events_fingerprint_unique;
ALTER INDEX IF EXISTS app_error_events_pkey RENAME TO error_events_pkey;
ALTER SEQUENCE IF EXISTS app_error_events_id_seq RENAME TO error_events_id_seq;
ALTER TABLE app_error_events RENAME TO error_events;
SQL);
    }
};
