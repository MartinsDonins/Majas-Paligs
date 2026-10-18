<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Package C — parking (MVP-SPEC C.1). Tenant tables follow §0 (building_id NOT NULL, index led by building_id,
 * UNIQUE(building_id, id), composite FKs). Plates exist only as XChaCha20-Poly1305 ciphertext + a per-building
 * blind index (PlateVault); no plaintext plate column anywhere.
 *
 * - permits: a main permit needs a vehicle while active (CHECK); overlapping active permits with the same number
 *   and type are impossible (EXCLUDE … gist); the vehicle FK is composite (building, apartment, vehicle) so a permit
 *   can never point at another apartment's vehicle, and deleting a vehicle only nulls vehicle_id — which the CHECK
 *   rejects for an active main permit (VehicleRemovalService revokes it first).
 * - plate_lookups: append-only (UPDATE always rejected, DELETE only by majas_retention for rows older than 6 months,
 *   SECURITY INVOKER guard), no UPDATE/DELETE grant for majas_app.
 * - Retention grants for prune:* (majas_retention): vehicles, sightings, plate_lookups (SELECT + DELETE),
 *   permits (SELECT + UPDATE of status columns), parking_officer_tasks (INSERT), sightings photo columns (UPDATE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parking_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('building_id')->constrained('buildings')->restrictOnDelete();
            $table->text('name');
            $table->integer('capacity_estimate')->nullable();
            $table->text('signage')->nullable();
            $table->text('notes')->nullable();
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();
            $table->unique(['building_id', 'name']);
            $table->unique(['building_id', 'id']);
        });
        DB::statement('ALTER TABLE parking_lots ADD CONSTRAINT parking_lots_capacity_check CHECK (capacity_estimate IS NULL OR capacity_estimate BETWEEN 0 AND 10000)');

        Schema::create('vehicles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('apartment_id');
            $table->binary('plate_ciphertext');
            $table->binary('plate_bidx');
            $table->smallInteger('plate_key_version');
            $table->char('country', 2)->default('LV');
            $table->boolean('is_company_car')->default(false);
            $table->text('status')->default('pending');
            $table->boolean('conflict')->default(false);
            $table->text('source')->default('self');
            $table->foreignId('declared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reconfirm_due_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'apartment_id']);
            $table->index(['building_id', 'plate_bidx']);
            $table->unique(['building_id', 'id']);
            $table->unique(['building_id', 'apartment_id', 'id']);
        });
        DB::statement("CREATE UNIQUE INDEX vehicles_active_bidx_unique ON vehicles (building_id, plate_bidx) WHERE status = 'active'");
        Pg::enumCheck('vehicles', 'status', ['pending', 'active', 'rejected', 'removed']);
        Pg::enumCheck('vehicles', 'source', ['self', 'officer', 'seed_list']);
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_country_check CHECK (country ~ '^[A-Z]{2}$')");
        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_bidx_length_check CHECK (octet_length(plate_bidx) = 32)');
        Pg::tenantFk('vehicles', 'apartment_id', 'apartments');

        Schema::create('permits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('apartment_id');
            $table->text('number');
            $table->text('type');
            $table->ulid('vehicle_id')->nullable();
            $table->text('issued_by')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->text('status')->default('active');
            $table->text('status_reason')->nullable();
            $table->unsignedBigInteger('replaced_by_permit_id')->nullable();
            $table->text('note_private')->nullable();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'apartment_id']);
            $table->index(['building_id', 'vehicle_id']);
            $table->unique(['building_id', 'id']);
        });
        Pg::enumCheck('permits', 'type', ['main', 'guest']);
        Pg::enumCheck('permits', 'status', ['active', 'returned', 'lost', 'revoked', 'expired']);
        Pg::enumCheck('permits', 'status_reason', ['vehicle_removed', 'account_deleted', 'retention', 'officer'], nullable: true);
        DB::statement("ALTER TABLE permits ADD CONSTRAINT permits_main_vehicle_check CHECK (type = 'guest' OR vehicle_id IS NOT NULL OR status <> 'active')");
        DB::statement('ALTER TABLE permits ADD CONSTRAINT permits_period_check CHECK (valid_to IS NULL OR valid_to >= valid_from)');
        DB::statement('ALTER TABLE permits ADD CONSTRAINT permits_number_check CHECK (char_length(number) BETWEEN 1 AND 32)');
        DB::statement('ALTER TABLE permits ADD CONSTRAINT permits_note_check CHECK (note_private IS NULL OR char_length(note_private) <= 500)');
        DB::statement("ALTER TABLE permits ADD CONSTRAINT permits_no_overlap EXCLUDE USING gist (
            building_id WITH =, number WITH =, type WITH =,
            daterange(valid_from, coalesce(valid_to, 'infinity'::date), '[]') WITH &&
        ) WHERE (status = 'active')");
        Pg::tenantFk('permits', 'apartment_id', 'apartments');
        // A permit can never reference a vehicle of another apartment; deleting the vehicle nulls ONLY vehicle_id.
        DB::statement('ALTER TABLE permits ADD CONSTRAINT permits_vehicle_tenant_fk FOREIGN KEY (building_id, apartment_id, vehicle_id)
            REFERENCES vehicles (building_id, apartment_id, id) ON DELETE SET NULL (vehicle_id)');
        DB::statement('ALTER TABLE permits ADD CONSTRAINT permits_replaced_by_tenant_fk FOREIGN KEY (building_id, replaced_by_permit_id)
            REFERENCES permits (building_id, id) ON DELETE SET NULL (replaced_by_permit_id)');

        Schema::create('parking_officer_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->text('kind');
            $table->unsignedBigInteger('permit_id')->nullable();
            $table->ulid('vehicle_id')->nullable();
            $table->text('status')->default('open');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('done_at')->nullable();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'status']);
            $table->unique(['building_id', 'id']);
        });
        Pg::enumCheck('parking_officer_tasks', 'kind', ['permit_without_vehicle', 'vehicle_conflict', 'vehicle_verify']);
        Pg::enumCheck('parking_officer_tasks', 'status', ['open', 'done', 'dismissed']);
        DB::statement('ALTER TABLE parking_officer_tasks ADD CONSTRAINT parking_officer_tasks_permit_tenant_fk FOREIGN KEY (building_id, permit_id)
            REFERENCES permits (building_id, id) ON DELETE SET NULL (permit_id)');
        DB::statement('ALTER TABLE parking_officer_tasks ADD CONSTRAINT parking_officer_tasks_vehicle_tenant_fk FOREIGN KEY (building_id, vehicle_id)
            REFERENCES vehicles (building_id, id) ON DELETE SET NULL (vehicle_id)');

        Schema::create('apartment_parking_declarations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('apartment_id');
            $table->timestampTz('declared_at');
            $table->boolean('has_no_vehicle')->default(false);
            $table->foreignId('declared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->unique('apartment_id');
            $table->index(['building_id', 'declared_at']);
            $table->unique(['building_id', 'id']);
        });
        Pg::tenantFk('apartment_parking_declarations', 'apartment_id', 'apartments', 'CASCADE');

        Schema::create('plate_lookups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            // Plain bigint, no FK: a pruned user tombstone must never cascade into or block the log.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->binary('plate_bidx');
            $table->boolean('result');
            $table->text('reason_code');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'created_at']);
            $table->index(['building_id', 'plate_bidx']);
            $table->index(['building_id', 'user_id', 'created_at']);
            $table->unique(['building_id', 'id']);
        });
        Pg::enumCheck('plate_lookups', 'reason_code', ['blocking', 'unknown_car', 'own_check', 'other', 'declaration', 'sighting']);

        Schema::create('sightings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('parking_lot_id');
            $table->binary('plate_ciphertext');
            $table->binary('plate_bidx');
            $table->smallInteger('plate_key_version');
            $table->char('country', 2)->default('LV');
            $table->timestampTz('seen_at');
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('reporter_apartment_id');
            $table->boolean('matched_registered')->default(false);
            $table->text('photo_path')->nullable();
            $table->integer('photo_bytes')->nullable();
            $table->timestampTz('photo_deleted_at')->nullable();
            $table->text('status')->default('open');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('handled_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'plate_bidx', 'seen_at']);
            $table->index(['building_id', 'created_at']);
            $table->unique(['building_id', 'id']);
        });
        Pg::enumCheck('sightings', 'status', ['open', 'disputed', 'dismissed', 'resolved']);
        DB::statement("ALTER TABLE sightings ADD CONSTRAINT sightings_country_check CHECK (country ~ '^[A-Z]{2}$')");
        DB::statement('ALTER TABLE sightings ADD CONSTRAINT sightings_photo_bytes_check CHECK (photo_bytes IS NULL OR photo_bytes BETWEEN 1 AND 8388608)');
        Pg::tenantFk('sightings', 'parking_lot_id', 'parking_lots');
        Pg::tenantFk('sightings', 'reporter_apartment_id', 'apartments');

        Schema::create('plate_sighting_stats', function (Blueprint $table): void {
            $table->unsignedBigInteger('building_id');
            $table->binary('plate_bidx');
            $table->smallInteger('sightings_30d')->default(0);
            $table->smallInteger('distinct_reporter_apartments_30d')->default(0);
            $table->smallInteger('days_seen_60d')->default(0);
            $table->timestampTz('last_seen_at')->nullable();
            $table->boolean('is_repeat')->default(false);
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['building_id', 'plate_bidx']);
            $table->foreign('building_id')->references('id')->on('buildings')->cascadeOnDelete();
        });

        Schema::create('parking_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('building_id');
            $table->text('number');
            $table->text('kind');
            $table->text('format');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('requested_role')->nullable();
            $table->timestampTz('requested_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approved_role')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->integer('row_count')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->unique(['building_id', 'number']);
            $table->unique(['building_id', 'id']);
        });
        Pg::enumCheck('parking_exports', 'kind', ['police', 'manager']);
        Pg::enumCheck('parking_exports', 'format', ['csv', 'pdf']);
        DB::statement('ALTER TABLE parking_exports ADD CONSTRAINT parking_exports_four_eyes_check CHECK (approved_by IS NULL OR approved_by <> requested_by)');
        DB::statement("ALTER TABLE parking_exports ADD CONSTRAINT parking_exports_number_check CHECK (number ~ '^PE-[0-9]{4}-[0-9]{4,}$')");

        DB::unprepared(<<<'SQL'
-- plate_lookups: append-only log (same pattern as audit_entries). UPDATE always rejected (audit_reject_update),
-- DELETE only by majas_retention for rows older than 6 months (SECURITY INVOKER: current_user must be the caller).
CREATE OR REPLACE FUNCTION plate_lookups_delete_guard() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER AS $fn$
BEGIN
    IF current_user <> 'majas_retention' OR session_user <> 'majas_retention' THEN
        RAISE EXCEPTION 'plate_lookups: DELETE allowed only for majas_retention' USING ERRCODE = '42501';
    END IF;
    IF OLD.created_at >= now() - interval '6 months' THEN
        RAISE EXCEPTION 'plate_lookups: row % is inside the retention window', OLD.id USING ERRCODE = '42501';
    END IF;
    RETURN OLD;
END
$fn$;

CREATE TRIGGER plate_lookups_no_update BEFORE UPDATE ON plate_lookups
    FOR EACH ROW EXECUTE FUNCTION audit_reject_update();
CREATE TRIGGER plate_lookups_delete BEFORE DELETE ON plate_lookups
    FOR EACH ROW EXECUTE FUNCTION plate_lookups_delete_guard();

REVOKE UPDATE, DELETE, TRUNCATE ON plate_lookups FROM majas_app;

-- Retention role (prune:* in the scheduler only).
GRANT SELECT, DELETE ON plate_lookups, sightings, vehicles TO majas_retention;
GRANT UPDATE (photo_path, photo_deleted_at) ON sightings TO majas_retention;
GRANT SELECT ON permits, parking_officer_tasks, apartments, plate_sighting_stats TO majas_retention;
GRANT UPDATE (status, status_reason, updated_at) ON permits TO majas_retention;
GRANT INSERT ON parking_officer_tasks TO majas_retention;
GRANT DELETE ON plate_sighting_stats TO majas_retention;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS parking_exports;
DROP TABLE IF EXISTS plate_sighting_stats;
DROP TABLE IF EXISTS sightings;
DROP TABLE IF EXISTS plate_lookups;
DROP TABLE IF EXISTS apartment_parking_declarations;
DROP TABLE IF EXISTS parking_officer_tasks;
DROP TABLE IF EXISTS permits;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS parking_lots;
DROP FUNCTION IF EXISTS plate_lookups_delete_guard();
SQL);
    }
};
