<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Package B — heating & outages (MVP-SPEC B.1). Tenant tables follow §0: building_id NOT NULL, index led by
 * building_id, UNIQUE(building_id, id) and composite FKs to parents. User references are plain FKs with
 * ON DELETE SET NULL so a pruned tombstone never blocks (house data stays, pointing at nobody).
 *
 * - `section_ids` integrity: BEFORE INSERT OR UPDATE trigger assert_section_ids_in_building() (SECURITY INVOKER)
 *   on survey_rounds + manager_reports; BEFORE DELETE guard on sections refuses deleting a referenced section.
 * - manager_reports are immutable in number, scope, sharing and snapshot (BEFORE UPDATE guard).
 * - Restricted projection fields (riser_status.floors_reported, top_floor_only) are never selected for residents
 *   (ResidentRiserView); the DB keeps them for staff views only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('building_id')->constrained('buildings')->restrictOnDelete();
            $table->jsonb('title');
            $table->text('kind')->default('heating_check');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->text('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['building_id', 'status']);
            $table->unique(['building_id', 'id']);
        });
        DB::statement('ALTER TABLE survey_rounds ADD COLUMN section_ids bigint[] NULL');
        Pg::enumCheck('survey_rounds', 'kind', ['heating_check']);
        Pg::enumCheck('survey_rounds', 'status', ['draft', 'open', 'closed']);
        DB::statement('ALTER TABLE survey_rounds ADD CONSTRAINT survey_rounds_period_check CHECK (ends_at > starts_at)');

        Schema::create('heating_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('apartment_id');
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('survey_round_id')->nullable();
            $table->uuid('submission_id');
            $table->text('status');
            $table->decimal('temperature_c', 3, 1)->nullable();
            $table->text('triage')->nullable();
            $table->text('note_private')->nullable();
            $table->text('source')->default('self');
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reported_at');
            $table->timestampTz('valid_until');
            $table->timestampTz('superseded_at')->nullable();
            $table->uuid('superseded_by')->nullable();
            $table->timestampTz('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('hidden_reason')->nullable();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'reported_at']);
            $table->index(['building_id', 'submission_id']);
            $table->index(['building_id', 'apartment_id']);
            $table->unique(['building_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX heating_reports_active_unique ON heating_reports (room_id, coalesce(survey_round_id, 0)) WHERE superseded_at IS NULL AND hidden_at IS NULL');
        Pg::enumCheck('heating_reports', 'status', ['cold', 'lukewarm', 'warm', 'overheated']);
        Pg::enumCheck('heating_reports', 'triage', ['air_suspected', 'valve_closed', 'whole_flat', 'unknown'], nullable: true);
        Pg::enumCheck('heating_reports', 'source', ['self', 'proxy', 'phone']);
        DB::statement('ALTER TABLE heating_reports ADD CONSTRAINT heating_reports_temperature_check CHECK (temperature_c IS NULL OR (temperature_c >= 0 AND temperature_c <= 40))');
        DB::statement('ALTER TABLE heating_reports ADD CONSTRAINT heating_reports_note_check CHECK (note_private IS NULL OR char_length(note_private) <= 280)');
        DB::statement('ALTER TABLE heating_reports ADD CONSTRAINT heating_reports_validity_check CHECK (valid_until > reported_at)');
        Pg::tenantFk('heating_reports', 'apartment_id', 'apartments');
        Pg::tenantFk('heating_reports', 'room_id', 'rooms');
        Pg::tenantFk('heating_reports', 'survey_round_id', 'survey_rounds');
        Pg::retentionManaged('heating_reports');

        Schema::create('incidents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('building_id');
            $table->text('kind');
            $table->text('origin');
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('riser_id')->nullable();
            $table->smallInteger('column_position')->nullable();
            $table->text('scope_key');
            $table->text('status')->default('reported');
            $table->timestampTz('started_at');
            $table->timestampTz('expected_end_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->jsonb('reason')->nullable();
            $table->text('manager_ref')->nullable();
            $table->jsonb('manager_reply')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'status', 'kind']);
            $table->unique(['building_id', 'id']);
        });
        DB::statement('ALTER TABLE incidents ADD COLUMN floors int4range NULL');
        DB::statement("ALTER TABLE incidents ADD COLUMN is_open boolean GENERATED ALWAYS AS (status NOT IN ('resolved', 'rejected')) STORED");
        DB::statement("CREATE UNIQUE INDEX incidents_dedup_unique ON incidents (building_id, kind, scope_key) WHERE is_open AND origin = 'resident'");
        Pg::enumCheck('incidents', 'kind', ['water_cold', 'water_hot', 'heating_none']);
        Pg::enumCheck('incidents', 'origin', ['resident', 'notice']);
        Pg::enumCheck('incidents', 'status', ['reported', 'confirmed', 'manager_notified', 'in_progress', 'resolved', 'rejected']);
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_scope_key_check CHECK (scope_key ~ '^(riser:[0-9]+|col:[0-9]+:[0-9]+|section:[0-9]+|building)$')");
        Pg::tenantFk('incidents', 'section_id', 'sections');
        Pg::tenantFk('incidents', 'riser_id', 'risers');

        Schema::create('incident_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->ulid('incident_id');
            $table->unsignedBigInteger('apartment_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('source')->default('self');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->unique(['incident_id', 'apartment_id']);
            $table->index(['building_id', 'apartment_id']);
            $table->unique(['building_id', 'id']);
        });
        Pg::enumCheck('incident_confirmations', 'source', ['self', 'proxy', 'phone']);
        Pg::tenantFk('incident_confirmations', 'incident_id', 'incidents', 'CASCADE');
        Pg::tenantFk('incident_confirmations', 'apartment_id', 'apartments');

        Schema::create('riser_status', function (Blueprint $table): void {
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('section_id');
            $table->unsignedBigInteger('riser_id');
            $table->text('round_key');
            $table->text('status')->default('none');
            $table->boolean('confirmed')->default(false);
            $table->smallInteger('distinct_apartments')->default(0);
            $table->smallInteger('floors_covered')->default(0);
            $table->jsonb('status_counts')->default('{}');
            $table->smallInteger('coverage_total')->default(0);
            $table->boolean('conflict')->default(false);
            $table->text('checked_state')->nullable();
            $table->boolean('disputed')->default(false);
            $table->boolean('whole_riser_under')->default(false);
            $table->boolean('top_floor_only')->default(false);
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['riser_id', 'round_key']);
            $table->index(['building_id', 'round_key']);
        });
        DB::statement("ALTER TABLE riser_status ADD COLUMN floors_reported smallint[] NOT NULL DEFAULT '{}'");
        Pg::enumCheck('riser_status', 'status', ['cold', 'lukewarm', 'warm', 'overheated', 'mixed', 'none']);
        Pg::enumCheck('riser_status', 'checked_state', ['normal', 'air_vented', 'fixed', 'escalated'], nullable: true);
        DB::statement("ALTER TABLE riser_status ADD CONSTRAINT riser_status_round_key_check CHECK (round_key ~ '^(rolling|round:[0-9]+)$')");
        Pg::tenantFk('riser_status', 'riser_id', 'risers', 'CASCADE');
        Pg::tenantFk('riser_status', 'section_id', 'sections', 'CASCADE');

        Schema::create('column_status', function (Blueprint $table): void {
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('section_id');
            $table->smallInteger('position');
            $table->text('round_key');
            $table->text('worst_confirmed_status')->nullable();
            $table->text('unconfirmed_status')->nullable();
            $table->boolean('has_unconfirmed')->default(false);
            $table->smallInteger('distinct_apartments')->default(0);
            $table->smallInteger('floors_covered')->default(0);
            $table->smallInteger('coverage_total')->default(0);
            $table->boolean('disputed')->default(false);
            $table->boolean('checked')->default(false);
            $table->boolean('conflict')->default(false);
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['section_id', 'position', 'round_key']);
            $table->index(['building_id', 'round_key']);
        });
        Pg::enumCheck('column_status', 'worst_confirmed_status', ['cold', 'lukewarm', 'warm', 'overheated', 'mixed'], nullable: true);
        Pg::enumCheck('column_status', 'unconfirmed_status', ['cold', 'lukewarm', 'warm', 'overheated', 'mixed'], nullable: true);
        DB::statement("ALTER TABLE column_status ADD CONSTRAINT column_status_round_key_check CHECK (round_key ~ '^(rolling|round:[0-9]+)$')");
        Pg::tenantFk('column_status', 'section_id', 'sections', 'CASCADE');

        Schema::create('riser_checks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('riser_id');
            $table->text('result');
            $table->timestampTz('checked_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note_private')->nullable();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'riser_id', 'checked_at']);
            $table->unique(['building_id', 'id']);
        });
        Pg::enumCheck('riser_checks', 'result', ['normal', 'air_vented', 'fixed', 'escalated']);
        DB::statement('ALTER TABLE riser_checks ADD CONSTRAINT riser_checks_note_check CHECK (note_private IS NULL OR char_length(note_private) <= 500)');
        Pg::tenantFk('riser_checks', 'riser_id', 'risers', 'CASCADE');

        Schema::create('riser_disputes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('apartment_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('riser_id');
            $table->text('status')->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->unique(['building_id', 'id']);
        });
        DB::statement("CREATE INDEX riser_disputes_open_idx ON riser_disputes (building_id, riser_id) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX riser_disputes_open_unique ON riser_disputes (riser_id, apartment_id) WHERE status = 'open'");
        Pg::enumCheck('riser_disputes', 'status', ['open', 'resolved', 'rejected']);
        Pg::tenantFk('riser_disputes', 'apartment_id', 'apartments');
        Pg::tenantFk('riser_disputes', 'room_id', 'rooms');
        Pg::tenantFk('riser_disputes', 'riser_id', 'risers', 'CASCADE');

        Schema::create('manager_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('building_id');
            $table->text('number');
            $table->text('kind');
            $table->jsonb('params')->default('{}');
            $table->jsonb('sharing')->default('{}');
            $table->jsonb('snapshot')->default('{}');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('generated_at');
            $table->timestampTz('sent_at')->nullable();
            $table->text('manager_ref')->nullable();
            $table->jsonb('response')->nullable();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->unique(['building_id', 'number']);
            $table->unique(['building_id', 'id']);
        });
        DB::statement('ALTER TABLE manager_reports ADD COLUMN section_ids bigint[] NOT NULL');
        Pg::enumCheck('manager_reports', 'kind', ['heating', 'outage']);
        DB::statement("ALTER TABLE manager_reports ADD CONSTRAINT manager_reports_number_check CHECK (number ~ '^MP-[0-9]{4}-[0-9]{4,}$')");

        DB::unprepared(<<<'SQL'
-- section_ids ⊆ sections of the SAME building, non-empty, no NULLs, no duplicates (MVP-SPEC B.1).
CREATE OR REPLACE FUNCTION assert_section_ids_in_building() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
DECLARE
    v_count    int;
    v_distinct int;
    v_found    int;
BEGIN
    IF NEW.section_ids IS NULL THEN
        RETURN NEW;
    END IF;
    v_count := coalesce(array_length(NEW.section_ids, 1), 0);
    IF v_count = 0 THEN
        RAISE EXCEPTION '%: section_ids must not be empty', TG_TABLE_NAME USING ERRCODE = '23514';
    END IF;
    IF array_position(NEW.section_ids, NULL) IS NOT NULL THEN
        RAISE EXCEPTION '%: section_ids must not contain NULL', TG_TABLE_NAME USING ERRCODE = '23514';
    END IF;
    SELECT count(DISTINCT x) INTO v_distinct FROM unnest(NEW.section_ids) AS x;
    IF v_distinct <> v_count THEN
        RAISE EXCEPTION '%: section_ids must not contain duplicates', TG_TABLE_NAME USING ERRCODE = '23514';
    END IF;
    SELECT count(*) INTO v_found FROM public.sections s WHERE s.building_id = NEW.building_id AND s.id = ANY (NEW.section_ids);
    IF v_found <> v_count THEN
        RAISE EXCEPTION '%: section_ids reference a section outside building %', TG_TABLE_NAME, NEW.building_id USING ERRCODE = '23503';
    END IF;
    RETURN NEW;
END
$fn$;

CREATE TRIGGER survey_rounds_section_ids BEFORE INSERT OR UPDATE OF section_ids, building_id ON survey_rounds
    FOR EACH ROW EXECUTE FUNCTION assert_section_ids_in_building();
CREATE TRIGGER manager_reports_section_ids BEFORE INSERT OR UPDATE OF section_ids, building_id ON manager_reports
    FOR EACH ROW EXECUTE FUNCTION assert_section_ids_in_building();

CREATE OR REPLACE FUNCTION sections_delete_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public AS $fn$
BEGIN
    IF EXISTS (SELECT 1 FROM public.survey_rounds r WHERE r.building_id = OLD.building_id AND OLD.id = ANY (r.section_ids))
       OR EXISTS (SELECT 1 FROM public.manager_reports m WHERE m.building_id = OLD.building_id AND OLD.id = ANY (m.section_ids)) THEN
        RAISE EXCEPTION 'section % is referenced by a survey round or a manager report', OLD.id USING ERRCODE = '23503';
    END IF;
    RETURN OLD;
END
$fn$;

CREATE TRIGGER sections_section_ids_guard BEFORE DELETE ON sections
    FOR EACH ROW EXECUTE FUNCTION sections_delete_guard();

-- A generated manager report is a record of what was exported: number, scope, sharing and snapshot are frozen.
CREATE OR REPLACE FUNCTION manager_reports_immutable() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER AS $fn$
BEGIN
    IF NEW.number IS DISTINCT FROM OLD.number
       OR NEW.kind IS DISTINCT FROM OLD.kind
       OR NEW.section_ids IS DISTINCT FROM OLD.section_ids
       OR NEW.sharing IS DISTINCT FROM OLD.sharing
       OR NEW.snapshot IS DISTINCT FROM OLD.snapshot
       OR NEW.params IS DISTINCT FROM OLD.params
       OR NEW.generated_at IS DISTINCT FROM OLD.generated_at
       OR NEW.building_id IS DISTINCT FROM OLD.building_id THEN
        RAISE EXCEPTION 'manager_reports: number, scope, sharing and snapshot are immutable' USING ERRCODE = '42501';
    END IF;
    RETURN NEW;
END
$fn$;

CREATE TRIGGER manager_reports_frozen BEFORE UPDATE ON manager_reports
    FOR EACH ROW EXECUTE FUNCTION manager_reports_immutable();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS sections_section_ids_guard ON sections;
DROP TABLE IF EXISTS manager_reports;
DROP TABLE IF EXISTS riser_disputes;
DROP TABLE IF EXISTS riser_checks;
DROP TABLE IF EXISTS column_status;
DROP TABLE IF EXISTS riser_status;
DROP TABLE IF EXISTS incident_confirmations;
DROP TABLE IF EXISTS incidents;
DROP TABLE IF EXISTS heating_reports;
DROP TABLE IF EXISTS survey_rounds;
DROP FUNCTION IF EXISTS manager_reports_immutable();
DROP FUNCTION IF EXISTS sections_delete_guard();
DROP FUNCTION IF EXISTS assert_section_ids_in_building();
SQL);
    }
};
