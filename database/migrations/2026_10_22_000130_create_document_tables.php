<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document templates and the house's own copies (ROUND2-SPEC §3.3–3.4, D7, GOV-011):
 * - document_templates (platform, no building_id): versioned LV / RU masters (Markdown with locked / editable markers).
 *   A PUBLISHED version is immutable (trigger) and cannot be deleted — a change is a new version.
 * - building_documents (tenant): the house's edited copy of a master (DPA, DPA annex, privacy notice, DPIA-lite,
 *   parking decision), LV + RU bodies, DPIA answers, status draft → final → sent → recorded (DPA) / superseded.
 *   Once a document left `draft` its bodies and answers are frozen (trigger); a change is a new version.
 *   Bodies hold no personal data except the controller name / contact the house typed (TemplateLinter).
 * - building_privacy_notices.document_id → composite tenant FK to building_documents.
 */
return new class extends Migration
{
    private const KINDS = ['dpa', 'dpa_annex', 'privacy_notice', 'dpia_lite', 'parking_decision'];

    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table): void {
            $table->id();
            $table->text('kind');
            $table->integer('version');
            $table->text('locale');
            $table->text('title');
            $table->text('body');
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['kind', 'version', 'locale']);
        });
        DB::statement("ALTER TABLE document_templates ADD COLUMN required_markers text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE document_templates ADD COLUMN placeholders text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE document_templates ADD COLUMN covers_modules text[] NOT NULL DEFAULT '{}'");
        Pg::enumCheck('document_templates', 'kind', self::KINDS);
        Pg::enumCheck('document_templates', 'locale', ['lv', 'ru']);

        Schema::create('building_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('building_id')->constrained('buildings')->restrictOnDelete();
            $table->text('kind');
            $table->foreignId('template_id')->constrained('document_templates')->restrictOnDelete();
            $table->integer('template_version');
            $table->integer('version');
            $table->text('body_lv');
            $table->text('body_ru');
            $table->jsonb('answers')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->text('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('finalized_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('recorded_at')->nullable();
            $table->char('superseded_by', 26)->nullable();
            $table->timestampsTz();
            $table->unique(['building_id', 'kind', 'version']);
            $table->unique(['building_id', 'id']);
            $table->index(['building_id', 'kind', 'status']);
        });
        Pg::enumCheck('building_documents', 'kind', self::KINDS);
        Pg::enumCheck('building_documents', 'status', ['draft', 'final', 'sent', 'recorded', 'superseded']);
        Pg::tenantFk('building_documents', 'superseded_by', 'building_documents');
        Pg::tenantFk('building_privacy_notices', 'document_id', 'building_documents');

        DB::unprepared(<<<'SQL'
ALTER TABLE document_templates ADD CONSTRAINT document_templates_version_check CHECK (version > 0);
ALTER TABLE document_templates ADD CONSTRAINT document_templates_modules_check
    CHECK (covers_modules <@ ARRAY['core','heating','outages','parking','roster']::text[]);
ALTER TABLE building_documents ADD CONSTRAINT building_documents_version_check CHECK (version > 0 AND template_version > 0);
ALTER TABLE building_documents ADD CONSTRAINT building_documents_body_len_check
    CHECK (char_length(body_lv) <= 100000 AND char_length(body_ru) <= 100000);
ALTER TABLE building_documents ADD CONSTRAINT building_documents_lifecycle_check CHECK (
    (status = 'draft' OR finalized_at IS NOT NULL)
    AND (status NOT IN ('sent', 'recorded') OR kind NOT IN ('dpa', 'dpa_annex') OR sent_at IS NOT NULL)
    AND (status <> 'recorded' OR recorded_at IS NOT NULL)
);

-- A published master is immutable and kept forever (buildings' documents reference it).
CREATE OR REPLACE FUNCTION document_templates_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.published_at IS NOT NULL THEN
            RAISE EXCEPTION 'document_templates: a published template version cannot be deleted';
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.published_at IS NOT NULL AND (
        NEW.body IS DISTINCT FROM OLD.body OR NEW.title IS DISTINCT FROM OLD.title
        OR NEW.kind IS DISTINCT FROM OLD.kind OR NEW.version IS DISTINCT FROM OLD.version
        OR NEW.locale IS DISTINCT FROM OLD.locale OR NEW.published_at IS DISTINCT FROM OLD.published_at
        OR NEW.required_markers IS DISTINCT FROM OLD.required_markers
        OR NEW.placeholders IS DISTINCT FROM OLD.placeholders
        OR NEW.covers_modules IS DISTINCT FROM OLD.covers_modules
    ) THEN
        RAISE EXCEPTION 'document_templates: a published template version is immutable — publish a new version';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER document_templates_immutable BEFORE UPDATE OR DELETE ON document_templates
    FOR EACH ROW EXECUTE FUNCTION document_templates_immutable();

-- Once a document left draft, what was finalised / sent / signed stays as it was.
CREATE OR REPLACE FUNCTION building_documents_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' AND (
        NEW.body_lv IS DISTINCT FROM OLD.body_lv OR NEW.body_ru IS DISTINCT FROM OLD.body_ru
        OR NEW.answers IS DISTINCT FROM OLD.answers OR NEW.kind IS DISTINCT FROM OLD.kind
        OR NEW.version IS DISTINCT FROM OLD.version OR NEW.building_id IS DISTINCT FROM OLD.building_id
        OR NEW.template_id IS DISTINCT FROM OLD.template_id
    ) THEN
        RAISE EXCEPTION 'building_documents: a finalised document is frozen — create a new version';
    END IF;
    IF NEW.status = 'draft' AND OLD.status <> 'draft' THEN
        RAISE EXCEPTION 'building_documents: a finalised document cannot return to draft';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER building_documents_frozen BEFORE UPDATE ON building_documents
    FOR EACH ROW EXECUTE FUNCTION building_documents_frozen();
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE building_privacy_notices DROP CONSTRAINT IF EXISTS building_privacy_notices_document_id_tenant_fk');
        // Dev rollback only (production rollback = previous image): notices published from a house document go too.
        DB::statement('DELETE FROM building_privacy_notices WHERE document_id IS NOT NULL');
        Schema::dropIfExists('building_documents');
        DB::statement('DROP FUNCTION IF EXISTS building_documents_frozen() CASCADE');
        Schema::dropIfExists('document_templates');
        DB::statement('DROP FUNCTION IF EXISTS document_templates_immutable() CASCADE');
    }
};
