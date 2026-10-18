<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review fixes (0.0.12):
 * - users.auth_epoch: bumped on every credential change (PIN set/reset, temp code, TOTP change, role revoke,
 *   "Aizmirst ierīces", erasure). EnsureSessionEpoch ends every other live session whose epoch is older.
 * - plate_lookups.result nullable: declaration / sighting / dispute rows store NULL, so neither the log nor the
 *   self-service GDPR export can become a registration oracle (MVP-SPEC C.5, C.7 AC 16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('auth_epoch')->default(0);
        });

        Schema::table('plate_lookups', function (Blueprint $table): void {
            $table->boolean('result')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('auth_epoch');
        });
    }
};
