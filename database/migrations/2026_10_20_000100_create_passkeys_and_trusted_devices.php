<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simpler admin login (ADR 0006, AUTH-006):
 * - passkeys: WebAuthn credentials (spatie/laravel-passkeys model shape). Global identity rows like users — no
 *   building_id; CoreDigify is the controller. `data` is the public key credential source (public key, counter,
 *   transports) — never a secret. credential_id is base64 on PostgreSQL (Passkey::encodeCredentialId).
 * - trusted_devices: "Uzticēties šai ierīcei 30 dienas" after a real MFA. Only the SHA-256 of the 32-byte cookie
 *   secret is stored; a row counts only while auth_epoch equals users.auth_epoch (every credential change, "Aizmirst
 *   ierīces" and erasure kill all of them at once). Expired rows are pruned by prune:login-tokens (majas_retention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('authenticatable_id')->constrained('users', indexName: 'passkeys_authenticatable_fk')->cascadeOnDelete();
            $table->text('name');
            $table->text('credential_id')->unique();
            $table->json('data');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
            $table->index('authenticatable_id');
        });

        Schema::create('trusted_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->unsignedInteger('auth_epoch');
            $table->text('label');
            $table->text('method');
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'expires_at']);
        });
        Pg::enumCheck('trusted_devices', 'method', ['totp', 'passkey']);
        Pg::retentionManaged('trusted_devices');
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('passkeys');
    }
};
