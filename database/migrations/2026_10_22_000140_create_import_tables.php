<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB-003 — shared CSV import pipeline (ROUND2-SPEC §4.2). Tenant tables (MVP-SPEC §0).
 *
 * - import_runs: one upload → mapping → validation → dry-run → commit run, resumable for 24 h. The CSV itself exists
 *   only as ImportVault ciphertext (payload_ciphertext, nulled on commit / discard / expiry); `summary` and
 *   `committed_counts` hold counts per validation code, never cell values. One commit per building + kind at a time
 *   (partial UNIQUE on status = 'committing').
 * - import_mapping_presets: column mapping per header layout (sha256 of the normalised header list; headers are not
 *   personal data), re-applied when the same sheet layout is uploaded again.
 * Retention (prune:imports, majas_retention): payload nulled at expiry, rows deleted 30 days after creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('building_id');
            $table->text('kind');
            $table->text('status')->default('uploaded');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->binary('file_sha256');
            $table->text('encoding')->default('utf-8');
            $table->text('delimiter')->default(';');
            $table->boolean('has_header')->default(true);
            $table->integer('row_count')->default(0);
            $table->jsonb('mapping')->default('{}');
            $table->jsonb('options')->default('{}');
            $table->binary('payload_ciphertext')->nullable();
            $table->jsonb('summary')->default('{}');
            $table->jsonb('committed_counts')->nullable();
            $table->text('error_code')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'kind', 'created_at']);
            $table->unique(['building_id', 'id']);
        });
        Pg::enumCheck('import_runs', 'kind', ['roster', 'permits']);
        Pg::enumCheck('import_runs', 'status', ['uploaded', 'mapped', 'validated', 'committing', 'committed', 'failed', 'discarded', 'expired']);
        Pg::enumCheck('import_runs', 'encoding', ['utf-8', 'windows-1257', 'windows-1251']);
        Pg::enumCheck('import_runs', 'delimiter', [';', ',', 'tab']);
        DB::statement('ALTER TABLE import_runs ADD CONSTRAINT import_runs_sha_length_check CHECK (octet_length(file_sha256) = 32)');
        DB::statement('ALTER TABLE import_runs ADD CONSTRAINT import_runs_rows_check CHECK (row_count BETWEEN 0 AND 100000)');
        // A finished run never keeps the sealed CSV.
        DB::statement("ALTER TABLE import_runs ADD CONSTRAINT import_runs_payload_check CHECK (payload_ciphertext IS NULL OR status IN ('uploaded', 'mapped', 'validated', 'committing'))");
        DB::statement("CREATE UNIQUE INDEX import_runs_one_commit ON import_runs (building_id, kind) WHERE status = 'committing'");
        DB::statement('CREATE INDEX import_runs_sha ON import_runs (building_id, kind, file_sha256)');

        Schema::create('import_mapping_presets', function (Blueprint $table): void {
            $table->unsignedBigInteger('building_id');
            $table->text('kind');
            $table->binary('header_signature');
            $table->jsonb('mapping');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['building_id', 'kind', 'header_signature']);
            $table->foreign('building_id')->references('id')->on('buildings')->cascadeOnDelete();
        });
        Pg::enumCheck('import_mapping_presets', 'kind', ['roster', 'permits']);
        DB::statement('ALTER TABLE import_mapping_presets ADD CONSTRAINT import_mapping_presets_sig_check CHECK (octet_length(header_signature) = 32)');

        DB::unprepared(<<<'SQL'
-- Retention role (prune:imports): expire runs (null the payload) and delete rows after 30 days.
GRANT SELECT, DELETE ON import_runs TO majas_retention;
GRANT UPDATE (status, payload_ciphertext, updated_at) ON import_runs TO majas_retention;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS import_mapping_presets;
DROP TABLE IF EXISTS import_runs;
SQL);
    }
};
