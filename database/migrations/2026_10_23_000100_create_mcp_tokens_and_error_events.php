<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Round 3 — the in-app MCP server (docs/MCP.md, ADR 0008).
 *
 * - mcp_tokens (platform data, NOT a tenant table): personal access tokens of a platform_admin. The secret exists only
 *   in the one-time display; the row keeps SHA-256(secret), the last four characters as a hint, the scopes (read /
 *   write), an optional expiry (a write token MUST expire), the owner's auth_epoch at creation (any credential change
 *   ends the token) and revocation. Normal DML for majas_app (revocation is an UPDATE; nothing is ever shown again).
 * - error_events: a lightweight, PII-free error log for `app_status` — one row per fingerprint (exception class +
 *   file + line) with a count, a scrubbed one-line message and first / last seen. Pruned after 30 days by
 *   `ops:heartbeat` (scheduler, majas_app). No request data, no user, no IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('label');
            $table->jsonb('scopes')->default('["read"]');
            $table->binary('token_hash')->unique();
            $table->text('token_hint');
            $table->unsignedInteger('auth_epoch')->default(0);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['user_id', 'revoked_at']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE mcp_tokens ADD CONSTRAINT mcp_tokens_hash_len_check CHECK (octet_length(token_hash) = 32);
ALTER TABLE mcp_tokens ADD CONSTRAINT mcp_tokens_label_len_check CHECK (char_length(label) BETWEEN 1 AND 60);
ALTER TABLE mcp_tokens ADD CONSTRAINT mcp_tokens_hint_len_check CHECK (char_length(token_hint) = 4);
ALTER TABLE mcp_tokens ADD CONSTRAINT mcp_tokens_scopes_check CHECK (
    jsonb_typeof(scopes) = 'array' AND scopes <@ '["read", "write"]'::jsonb AND scopes ? 'read'
);
ALTER TABLE mcp_tokens ADD CONSTRAINT mcp_tokens_write_expires_check CHECK (NOT (scopes ? 'write') OR expires_at IS NOT NULL);
SQL);

        Schema::create('error_events', function (Blueprint $table): void {
            $table->id();
            $table->char('fingerprint', 64)->unique();
            $table->text('exception_class');
            $table->text('location');
            $table->text('message');
            $table->text('origin');
            $table->unsignedInteger('count')->default(1);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->index('last_seen_at');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE error_events ADD CONSTRAINT error_events_lengths_check CHECK (
    char_length(exception_class) <= 200 AND char_length(location) <= 200 AND char_length(message) <= 200 AND char_length(origin) <= 120
);
SQL);
        Pg::enumCheck('error_events', 'origin', ['http', 'console', 'queue', 'mcp'], nullable: false);
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
        Schema::dropIfExists('mcp_tokens');
    }
};
