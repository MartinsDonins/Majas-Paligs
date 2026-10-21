<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Komanda — e-mail invitations to a building role (ROUND2-SPEC §2.1, ADMIN-006).
 * - role_invitations (tenant): the invitee's e-mail only as APP_KEY ciphertext (shown to building_admin) + HMAC (match on
 *   accept, duplicate check) + a masked hint; the 32-byte token only as its SHA-256 (the token itself lives in the mail
 *   fragment). Never manager_viewer. section_ids reuse assert_section_ids_in_building() (only when non-empty: other
 *   roles carry `{}`). App: SELECT/INSERT/UPDATE (no DELETE); retention deletes 30 days after closing.
 * - login_tokens: purpose `team_invite` (e-mail code bound to one invitation, so a code can never accept another).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('building_id')->constrained('buildings')->restrictOnDelete();
            $table->text('email_ciphertext');
            $table->binary('email_hmac');
            $table->text('email_hint');
            $table->text('role');
            $table->text('mandate_ref')->nullable();
            $table->text('locale')->default('lv');
            $table->binary('token_hash')->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resent_at')->nullable();
            $table->smallInteger('resend_count')->default(0);
            $table->index(['building_id', 'created_at']);
            $table->unique(['building_id', 'id']);
        });

        DB::statement("ALTER TABLE role_invitations ADD COLUMN section_ids bigint[] NOT NULL DEFAULT '{}'");
        Pg::enumCheck('role_invitations', 'role', ['building_admin', 'section_elder', 'parking_officer', 'volunteer']);
        Pg::enumCheck('role_invitations', 'locale', ['lv', 'ru']);

        DB::unprepared(<<<'SQL'
ALTER TABLE role_invitations ADD CONSTRAINT role_invitations_elder_sections_check CHECK (role <> 'section_elder' OR cardinality(section_ids) > 0);
ALTER TABLE role_invitations ADD CONSTRAINT role_invitations_closed_once_check CHECK (accepted_at IS NULL OR revoked_at IS NULL);
ALTER TABLE role_invitations ADD CONSTRAINT role_invitations_hmac_len_check CHECK (octet_length(email_hmac) = 32 AND octet_length(token_hash) = 32);
ALTER TABLE role_invitations ADD CONSTRAINT role_invitations_mandate_len_check CHECK (mandate_ref IS NULL OR char_length(mandate_ref) <= 200);
ALTER TABLE role_invitations ADD CONSTRAINT role_invitations_resend_check CHECK (resend_count BETWEEN 0 AND 3);
CREATE UNIQUE INDEX role_invitations_open_unique ON role_invitations (building_id, email_hmac, role, section_ids)
    WHERE accepted_at IS NULL AND revoked_at IS NULL;
CREATE TRIGGER role_invitations_section_ids BEFORE INSERT OR UPDATE OF section_ids, building_id ON role_invitations
    FOR EACH ROW WHEN (cardinality(NEW.section_ids) > 0) EXECUTE FUNCTION assert_section_ids_in_building();
REVOKE DELETE, TRUNCATE ON role_invitations FROM majas_app;
SQL);
        Pg::retentionManaged('role_invitations');

        DB::statement('ALTER TABLE login_tokens DROP CONSTRAINT login_tokens_purpose_check');
        Pg::enumCheck('login_tokens', 'purpose', ['login', 'email_change_old', 'email_change_new', 'step_up', 'team_invite']);
        Schema::table('login_tokens', function (Blueprint $table): void {
            $table->char('role_invitation_id', 26)->nullable();
        });
        DB::statement("ALTER TABLE login_tokens ADD CONSTRAINT login_tokens_invite_binding_check CHECK ((purpose = 'team_invite') = (role_invitation_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE login_tokens DROP CONSTRAINT IF EXISTS login_tokens_invite_binding_check');
        DB::table('login_tokens')->where('purpose', 'team_invite')->delete();
        Schema::table('login_tokens', function (Blueprint $table): void {
            $table->dropColumn('role_invitation_id');
        });
        DB::statement('ALTER TABLE login_tokens DROP CONSTRAINT login_tokens_purpose_check');
        Pg::enumCheck('login_tokens', 'purpose', ['login', 'email_change_old', 'email_change_new', 'step_up']);

        Schema::dropIfExists('role_invitations');
    }
};
