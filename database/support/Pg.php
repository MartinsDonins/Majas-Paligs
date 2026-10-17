<?php

declare(strict_types=1);

namespace Database\Support;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL helpers for migrations: CHECK constraints for text enums, composite tenant FKs,
 * partial unique indexes and grants (MVP-SPEC §0, A.2a).
 */
final class Pg
{
    /**
     * Add `CHECK (column IN (...))` for a text enum column.
     *
     * @param  list<string>  $values
     */
    public static function enumCheck(string $table, string $column, array $values, bool $nullable = false): void
    {
        $list = implode(', ', array_map(static fn (string $v): string => "'".str_replace("'", "''", $v)."'", $values));
        $expr = $nullable ? "{$column} IS NULL OR {$column} IN ({$list})" : "{$column} IN ({$list})";
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$expr})");
    }

    /**
     * Composite tenant FK: (building_id, $column) REFERENCES $parent(building_id, id).
     */
    public static function tenantFk(string $table, string $column, string $parent, string $onDelete = 'RESTRICT'): void
    {
        DB::statement(sprintf(
            'ALTER TABLE %1$s ADD CONSTRAINT %1$s_%2$s_tenant_fk FOREIGN KEY (building_id, %2$s) REFERENCES %3$s (building_id, id) ON DELETE %4$s',
            $table,
            $column,
            $parent,
            $onDelete,
        ));
    }

    /**
     * Revoke mutations from the application role on an append-only table.
     */
    public static function appendOnly(string $table): void
    {
        DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON {$table} FROM majas_app");
    }

    /**
     * Grant the retention role SELECT + DELETE on a retention-managed table.
     */
    public static function retentionManaged(string $table): void
    {
        DB::statement("GRANT SELECT, DELETE ON {$table} TO majas_retention");
    }
}
