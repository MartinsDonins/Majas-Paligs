<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Explicit connections as majas_app / majas_retention / majas_owner (separate sessions — outside the
 * RefreshDatabase transaction of the default connection), for privilege and concurrency tests.
 */
final class DbRoles
{
    public static function connect(string $role): Connection
    {
        $name = 'probe_'.$role;
        $base = config('database.connections.pgsql');

        $password = match ($role) {
            'majas_app' => (string) env('DB_APP_TEST_PASSWORD', 'app-dev-only'),
            'majas_retention' => (string) env('DB_RETENTION_PASSWORD', 'retention-dev-only'),
            default => (string) env('DB_OWNER_PASSWORD', 'owner-dev-only'),
        };

        config(["database.connections.{$name}" => array_merge($base, ['username' => $role, 'password' => $password])]);
        DB::purge($name);

        return DB::connection($name);
    }

    /**
     * Remove committed audit rows of a test chain (owner, triggers bypassed) — cleanup only.
     */
    public static function wipeChain(int $chainKey): void
    {
        $owner = self::connect('majas_owner');
        $owner->statement('ALTER TABLE audit_entries DISABLE TRIGGER audit_entries_delete');
        $owner->statement('ALTER TABLE audit_chain_anchors DISABLE TRIGGER audit_chain_anchors_no_update');
        $owner->delete('DELETE FROM audit_entries WHERE chain_key = ?', [$chainKey]);
        $owner->delete('DELETE FROM audit_chain_anchors WHERE chain_key = ?', [$chainKey]);
        $owner->delete('DELETE FROM audit_chain_heads WHERE chain_key = ?', [$chainKey]);
        $owner->statement('ALTER TABLE audit_chain_anchors ENABLE TRIGGER audit_chain_anchors_no_update');
        $owner->statement('ALTER TABLE audit_entries ENABLE TRIGGER audit_entries_delete');
    }
}
