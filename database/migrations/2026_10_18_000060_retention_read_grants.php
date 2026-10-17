<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * majas_retention needs to SEE the references that keep a user tombstone alive (prune:users).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT SELECT ON apartment_memberships, building_roles TO majas_retention');
    }

    public function down(): void
    {
        DB::statement('REVOKE SELECT ON apartment_memberships, building_roles FROM majas_retention');
    }
};
