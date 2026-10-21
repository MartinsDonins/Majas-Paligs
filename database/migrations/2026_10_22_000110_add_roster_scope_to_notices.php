<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Round 2 notices (ROUND2-SPEC §3.3, D2, D7, GOV-011 / GOV-012):
 * - `roster` joins the modules a notice may cover (building notices and the platform template versions).
 * - A building notice may now come from the house's own edited document (building_documents, kind privacy_notice):
 *   `document_id` (composite tenant FK added with building_documents in 000130) and `body_override` {lv, ru}
 *   (sanitised Markdown rendered instead of a template). Such a notice has no platform template version, so
 *   `template_version_id` becomes nullable — but a notice always has exactly one source of text (CHECK).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE building_privacy_notices DROP CONSTRAINT building_privacy_notices_modules_check;
ALTER TABLE building_privacy_notices ADD CONSTRAINT building_privacy_notices_modules_check
    CHECK (covers_modules <@ ARRAY['core','heating','outages','parking','roster']::text[]);
ALTER TABLE privacy_notice_versions DROP CONSTRAINT privacy_notice_versions_modules_check;
ALTER TABLE privacy_notice_versions ADD CONSTRAINT privacy_notice_versions_modules_check
    CHECK (covers_modules <@ ARRAY['account','core','heating','outages','parking','roster']::text[]);

ALTER TABLE building_privacy_notices ADD COLUMN document_id char(26) NULL;
ALTER TABLE building_privacy_notices ADD COLUMN body_override jsonb NULL;
ALTER TABLE building_privacy_notices ALTER COLUMN template_version_id DROP NOT NULL;
ALTER TABLE building_privacy_notices ADD CONSTRAINT building_privacy_notices_source_check
    CHECK ((template_version_id IS NOT NULL) <> (body_override IS NOT NULL));
ALTER TABLE building_privacy_notices ADD CONSTRAINT building_privacy_notices_override_check
    CHECK (body_override IS NULL OR (jsonb_typeof(body_override->'lv') = 'string' AND jsonb_typeof(body_override->'ru') = 'string' AND document_id IS NOT NULL));
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DELETE FROM building_privacy_notices WHERE template_version_id IS NULL;
ALTER TABLE building_privacy_notices DROP CONSTRAINT IF EXISTS building_privacy_notices_override_check;
ALTER TABLE building_privacy_notices DROP CONSTRAINT IF EXISTS building_privacy_notices_source_check;
ALTER TABLE building_privacy_notices ALTER COLUMN template_version_id SET NOT NULL;
ALTER TABLE building_privacy_notices DROP COLUMN body_override;
ALTER TABLE building_privacy_notices DROP COLUMN document_id;
UPDATE building_privacy_notices SET covers_modules = array_remove(covers_modules, 'roster');
UPDATE privacy_notice_versions SET covers_modules = array_remove(covers_modules, 'roster');
ALTER TABLE building_privacy_notices DROP CONSTRAINT building_privacy_notices_modules_check;
ALTER TABLE building_privacy_notices ADD CONSTRAINT building_privacy_notices_modules_check
    CHECK (covers_modules <@ ARRAY['core','heating','outages','parking']::text[]);
ALTER TABLE privacy_notice_versions DROP CONSTRAINT privacy_notice_versions_modules_check;
ALTER TABLE privacy_notice_versions ADD CONSTRAINT privacy_notice_versions_modules_check
    CHECK (covers_modules <@ ARRAY['account','core','heating','outages','parking']::text[]);
SQL);
    }
};
