<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Base privileges (MVP-SPEC A.2a). Runs as majas_owner. Every table created later by the owner is
 * automatically DML-accessible to majas_app; append-only tables revoke UPDATE/DELETE/TRUNCATE explicitly.
 * Extensions are created by roles.sql as superuser — repeated here as a no-op safety net.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE majas_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO majas_app');
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE majas_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO majas_app');
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE majas_owner IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO majas_app');
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE majas_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO majas_retention');

        // The Laravel migrations table is created before this migration runs.
        DB::statement('GRANT SELECT ON migrations TO majas_app');
    }

    public function down(): void
    {
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE majas_owner IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM majas_app');
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE majas_owner IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM majas_app');
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE majas_owner IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM majas_app');
        DB::statement('ALTER DEFAULT PRIVILEGES FOR ROLE majas_owner IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM majas_retention');
    }
};
