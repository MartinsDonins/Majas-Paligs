<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global identity tables (MVP-SPEC A.4): users (tombstone deletion), login_tokens, notifications,
 * failed jobs. CoreDigify is the controller of these rows (PLANNING §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->text('email')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->text('login_handle')->nullable();
            $table->text('pin_hash')->nullable();
            $table->smallInteger('pin_failed_count')->default(0);
            $table->timestampTz('pin_locked_until')->nullable();
            $table->boolean('pin_must_change')->default(false);
            $table->text('pin_temp_code_hash')->nullable();
            $table->timestampTz('pin_temp_code_expires_at')->nullable();
            $table->text('locale')->default('lv');
            $table->text('display_name')->nullable();
            $table->text('platform_role')->nullable();
            $table->text('text_size')->default('normal');
            $table->text('theme')->default('system');
            $table->timestampTz('is_adult_confirmed_at')->nullable();
            $table->text('notice_version_accepted')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            // MFA (TOTP via pragmarx/google2fa, encrypted at rest)
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
            $table->rememberToken();
            $table->timestampTz('anonymised_at')->nullable();
            $table->softDeletesTz();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE email IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX users_login_handle_unique ON users (login_handle) WHERE login_handle IS NOT NULL');
        Pg::enumCheck('users', 'locale', ['lv', 'ru']);
        Pg::enumCheck('users', 'platform_role', ['platform_admin'], nullable: true);
        Pg::enumCheck('users', 'text_size', ['normal', 'large']);
        Pg::enumCheck('users', 'theme', ['system', 'light', 'dark']);
        Pg::retentionManaged('users');

        Schema::create('login_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->text('email_hmac');
            $table->text('token_hash')->nullable();
            $table->text('code_hash')->nullable();
            $table->text('purpose');
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->text('ip_hmac')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('token_hash');
            $table->index(['email_hmac', 'created_at']);
        });
        Pg::enumCheck('login_tokens', 'purpose', ['login', 'email_change_old', 'email_change_new', 'step_up']);
        Pg::retentionManaged('login_tokens');

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at')->useCurrent();
        });

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('login_tokens');
        Schema::dropIfExists('users');
    }
};
