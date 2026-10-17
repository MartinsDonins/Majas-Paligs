<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * House data: building privacy notices (gate L-core), invites, memberships, building roles,
 * user/building exports (MVP-SPEC A.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_privacy_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('building_id')->constrained('buildings')->restrictOnDelete();
            $table->foreignId('template_version_id')->constrained('privacy_notice_versions')->restrictOnDelete();
            $table->jsonb('controller_snapshot');
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampsTz();
            $table->index(['building_id', 'published_at']);
            $table->unique(['building_id', 'id']);
        });
        DB::statement("ALTER TABLE building_privacy_notices ADD COLUMN covers_modules text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE building_privacy_notices ADD CONSTRAINT building_privacy_notices_modules_check CHECK (covers_modules <@ ARRAY['core','heating','outages','parking']::text[])");

        Schema::create('apartment_invites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('apartment_id');
            $table->text('code_hmac')->unique();
            $table->smallInteger('max_uses')->default(3);
            $table->smallInteger('uses')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('batch_ref')->nullable();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->unique(['building_id', 'id']);
        });
        DB::statement('CREATE INDEX apartment_invites_active_idx ON apartment_invites (building_id, apartment_id) WHERE revoked_at IS NULL');
        DB::statement('ALTER TABLE apartment_invites ADD CONSTRAINT apartment_invites_uses_check CHECK (uses >= 0 AND uses <= max_uses AND max_uses > 0)');
        Pg::tenantFk('apartment_invites', 'apartment_id', 'apartments', 'CASCADE');

        Schema::create('apartment_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('apartment_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('role');
            $table->text('status')->default('pending');
            $table->ulid('invite_id')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('reconfirm_due_at')->nullable();
            $table->unsignedBigInteger('helper_consent_by_membership_id')->nullable();
            $table->unsignedBigInteger('building_notice_id')->nullable();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'status']);
            $table->index(['building_id', 'user_id']);
            $table->unique(['building_id', 'id']);
        });
        DB::statement("CREATE UNIQUE INDEX apartment_memberships_active_unique ON apartment_memberships (apartment_id, user_id) WHERE status IN ('pending', 'approved')");
        Pg::enumCheck('apartment_memberships', 'role', ['owner', 'tenant', 'family', 'contact']);
        Pg::enumCheck('apartment_memberships', 'status', ['pending', 'approved', 'rejected', 'ended']);
        Pg::tenantFk('apartment_memberships', 'apartment_id', 'apartments');
        Pg::tenantFk('apartment_memberships', 'invite_id', 'apartment_invites', 'SET NULL (invite_id)');
        Pg::tenantFk('apartment_memberships', 'helper_consent_by_membership_id', 'apartment_memberships', 'SET NULL (helper_consent_by_membership_id)');
        Pg::tenantFk('apartment_memberships', 'building_notice_id', 'building_privacy_notices');

        Schema::create('building_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('role');
            $table->unsignedBigInteger('section_id')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('granted_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->text('mandate_ref')->nullable();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'role']);
            $table->index('user_id');
            $table->unique(['building_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX building_roles_active_unique ON building_roles (building_id, user_id, role, section_id) NULLS NOT DISTINCT WHERE revoked_at IS NULL');
        Pg::enumCheck('building_roles', 'role', ['building_admin', 'section_elder', 'parking_officer', 'volunteer', 'manager_viewer']);
        DB::statement("ALTER TABLE building_roles ADD CONSTRAINT building_roles_elder_section_check CHECK (role <> 'section_elder' OR section_id IS NOT NULL)");
        Pg::tenantFk('building_roles', 'section_id', 'sections');

        Schema::create('data_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('status')->default('queued');
            $table->text('path')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index('user_id');
        });
        Pg::enumCheck('data_exports', 'status', ['queued', 'ready', 'expired', 'failed']);

        Schema::create('building_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('building_id')->constrained('buildings')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status')->default('queued');
            $table->text('path')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index('building_id');
            $table->unique(['building_id', 'id']);
        });
        Pg::enumCheck('building_exports', 'status', ['queued', 'ready', 'expired', 'failed']);
    }

    public function down(): void
    {
        Schema::dropIfExists('building_exports');
        Schema::dropIfExists('data_exports');
        Schema::dropIfExists('building_roles');
        Schema::dropIfExists('apartment_memberships');
        Schema::dropIfExists('apartment_invites');
        Schema::dropIfExists('building_privacy_notices');
    }
};
