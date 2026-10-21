<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PARK-011 / PARK-012 / PARK-013 — permit register (ROUND2-SPEC §6.1):
 *
 * - permits.source (officer | import | change_vehicle) + import_run_id (composite FK → import_runs, SET NULL when the
 *   run row is pruned), register indexes.
 * - permit_plate_observations: "seen with this guest permit" evidence (D8 — guest permits stay NOT plate-bound). Plate
 *   only as PlateVault ciphertext + per-building blind index; the trigger refuses rows for a main permit. Retention
 *   6 months (prune:permit-observations, majas_retention); re-keyed with vehicles and sightings (plates:rekey).
 * - parking_exports.include_apartments: manager list with apartment numbers — CHECK manager only; the service and the
 *   policy additionally require settings.parking_rules_adopted_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permits', function (Blueprint $table): void {
            $table->text('source')->default('officer');
            $table->ulid('import_run_id')->nullable();
        });
        Pg::enumCheck('permits', 'source', ['officer', 'import', 'change_vehicle']);
        DB::statement('ALTER TABLE permits ADD CONSTRAINT permits_import_run_tenant_fk FOREIGN KEY (building_id, import_run_id)
            REFERENCES import_runs (building_id, id) ON DELETE SET NULL (import_run_id)');
        DB::statement('CREATE INDEX permits_register_idx ON permits (building_id, status, type)');
        DB::statement("CREATE INDEX permits_active_apartment_idx ON permits (building_id, apartment_id) WHERE status = 'active'");

        Schema::create('permit_plate_observations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('permit_id');
            $table->binary('plate_ciphertext');
            $table->binary('plate_bidx');
            $table->smallInteger('plate_key_version');
            $table->date('observed_on');
            $table->text('source');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'permit_id', 'observed_on']);
            $table->index(['building_id', 'plate_bidx']);
            $table->unique(['building_id', 'id']);
        });
        Pg::enumCheck('permit_plate_observations', 'source', ['import', 'officer']);
        DB::statement('ALTER TABLE permit_plate_observations ADD CONSTRAINT permit_plate_observations_bidx_length_check CHECK (octet_length(plate_bidx) = 32)');
        Pg::tenantFk('permit_plate_observations', 'permit_id', 'permits', 'CASCADE');

        Schema::table('parking_exports', function (Blueprint $table): void {
            $table->boolean('include_apartments')->default(false);
        });
        DB::statement("ALTER TABLE parking_exports ADD CONSTRAINT parking_exports_include_apartments_check CHECK (kind = 'manager' OR NOT include_apartments)");

        DB::unprepared(<<<'SQL'
-- Guest permits only (D8): an observation never attaches a plate to a MAIN permit.
CREATE OR REPLACE FUNCTION permit_observation_guest_only() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER AS $fn$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM permits p WHERE p.building_id = NEW.building_id AND p.id = NEW.permit_id AND p.type = 'guest') THEN
        RAISE EXCEPTION 'permit_plate_observations: permit % is not a guest permit', NEW.permit_id USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END
$fn$;

CREATE TRIGGER permit_plate_observations_guest_only BEFORE INSERT OR UPDATE OF permit_id, building_id ON permit_plate_observations
    FOR EACH ROW EXECUTE FUNCTION permit_observation_guest_only();

-- prune:permit-observations (6 months).
GRANT SELECT, DELETE ON permit_plate_observations TO majas_retention;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS permit_plate_observations;
DROP FUNCTION IF EXISTS permit_observation_guest_only();
ALTER TABLE parking_exports DROP CONSTRAINT IF EXISTS parking_exports_include_apartments_check;
ALTER TABLE parking_exports DROP COLUMN IF EXISTS include_apartments;
DROP INDEX IF EXISTS permits_active_apartment_idx;
DROP INDEX IF EXISTS permits_register_idx;
ALTER TABLE permits DROP CONSTRAINT IF EXISTS permits_import_run_tenant_fk;
ALTER TABLE permits DROP CONSTRAINT IF EXISTS permits_source_check;
ALTER TABLE permits DROP COLUMN IF EXISTS import_run_id;
ALTER TABLE permits DROP COLUMN IF EXISTS source;
SQL);
    }
};
