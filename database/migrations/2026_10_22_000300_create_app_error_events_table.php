<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OPS-001 — lightweight production error log (round 3): operators (and the platform MCP tool, through
 * App\Support\ErrorLog\ErrorLogReader) see what is failing without GitHub / Coolify / GlitchTip access.
 *
 * Platform table (no building_id). One row per fingerprint (exception class + first app frame + message shape),
 * written by App\Support\ErrorLog\ErrorLogRecorder from the exception handler, throttled per fingerprint.
 * NO personal data: the message is scrubbed (e-mails, phones, plates, IPs, quoted values, SQL bindings) and
 * truncated before it is stored; no request body, URL, user, building or stack trace is kept.
 *
 * Grants (roles.sql conventions, MVP-SPEC A.2a): majas_app may INSERT and bump an existing row (UPDATE of
 * message / count / last_seen only), never DELETE; majas_retention (prune:error-events, 30 days) SELECT + DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_error_events', function (Blueprint $table): void {
            $table->id();
            $table->text('fingerprint')->unique();
            $table->text('exception_class');
            $table->text('message');
            $table->text('file');
            $table->integer('line');
            $table->bigInteger('count')->default(1);
            $table->timestampTz('first_seen')->useCurrent();
            $table->timestampTz('last_seen')->useCurrent();
            $table->index('last_seen');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE app_error_events ADD CONSTRAINT app_error_events_fingerprint_check CHECK (fingerprint ~ '^[0-9a-f]{64}$');
ALTER TABLE app_error_events ADD CONSTRAINT app_error_events_class_check CHECK (char_length(exception_class) BETWEEN 1 AND 255);
ALTER TABLE app_error_events ADD CONSTRAINT app_error_events_message_check CHECK (char_length(message) <= 500);
ALTER TABLE app_error_events ADD CONSTRAINT app_error_events_file_check CHECK (char_length(file) <= 255);
ALTER TABLE app_error_events ADD CONSTRAINT app_error_events_count_check CHECK (count >= 1);
ALTER TABLE app_error_events ADD CONSTRAINT app_error_events_seen_check CHECK (last_seen >= first_seen);

-- majas_app: record and bump, never rewrite identity columns, never delete (default privileges gave full DML).
REVOKE UPDATE, DELETE ON app_error_events FROM majas_app;
GRANT UPDATE (message, count, last_seen) ON app_error_events TO majas_app;

-- Retention role (prune:error-events): rows not seen for 30 days.
GRANT SELECT, DELETE ON app_error_events TO majas_retention;
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_error_events');
    }
};
