<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizations, buildings and the physical structure (MVP-SPEC A.4). Every tenant table carries
 * building_id NOT NULL, an index led by building_id and UNIQUE(building_id, id); children reference
 * parents through composite FKs so cross-building links are impossible in SQL (PLANNING §4.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->text('name');
            $table->text('kind');
            $table->text('reg_no')->nullable();
            $table->timestampsTz();
        });
        Pg::enumCheck('organizations', 'kind', ['association', 'kopiba', 'manager', 'other']);

        Schema::create('buildings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->text('slug')->unique();
            $table->text('name');
            $table->text('address_line');
            $table->text('address_search');
            $table->text('postcode')->nullable();
            $table->text('city');
            $table->text('address_code')->nullable();
            $table->text('cadastre_designation')->nullable();
            $table->foreignId('series_id')->nullable()->constrained('building_series')->nullOnDelete();
            $table->smallInteger('floors');
            $table->integer('year_built')->nullable();
            $table->text('manager_name')->nullable();
            $table->text('manager_emergency_phone')->nullable();
            $table->text('default_locale')->default('lv');
            $table->jsonb('settings')->default('{}');
            $table->text('status')->default('draft');
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX buildings_address_code_unique ON buildings (address_code) WHERE address_code IS NOT NULL');
        DB::statement('CREATE INDEX buildings_address_search_trgm ON buildings USING gin (address_search gin_trgm_ops)');
        DB::statement("ALTER TABLE buildings ADD CONSTRAINT buildings_slug_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        Pg::enumCheck('buildings', 'default_locale', ['lv', 'ru']);
        Pg::enumCheck('buildings', 'status', ['draft', 'active', 'archived']);

        Schema::create('land_parcels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('building_id')->constrained('buildings')->restrictOnDelete();
            $table->text('cadastre_no');
            $table->decimal('area_m2', 10, 1)->nullable();
            $table->boolean('verified')->default(false);
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['building_id', 'cadastre_no']);
            $table->unique(['building_id', 'id']);
        });

        Schema::create('sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('building_id')->constrained('buildings')->restrictOnDelete();
            $table->smallInteger('number');
            $table->integer('first_apartment_no');
            $table->smallInteger('apartments_per_floor');
            $table->smallInteger('floors');
            $table->text('layout_code')->nullable();
            $table->foreignId('series_section_layout_id')->nullable()->constrained('series_section_layouts')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['building_id', 'number']);
            $table->unique(['building_id', 'id']);
        });
        DB::statement('ALTER TABLE sections ADD CONSTRAINT sections_shape_check CHECK (number > 0 AND floors > 0 AND apartments_per_floor > 0 AND first_apartment_no > 0)');

        Schema::create('section_land_parcel', function (Blueprint $table): void {
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('section_id');
            $table->unsignedBigInteger('land_parcel_id');
            $table->primary(['section_id', 'land_parcel_id']);
            $table->index('building_id');
        });
        Pg::tenantFk('section_land_parcel', 'section_id', 'sections', 'CASCADE');
        Pg::tenantFk('section_land_parcel', 'land_parcel_id', 'land_parcels', 'CASCADE');

        Schema::create('apartments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('section_id');
            $table->integer('number');
            $table->smallInteger('floor');
            $table->smallInteger('position');
            $table->text('side')->nullable();
            $table->smallInteger('rooms_count')->nullable();
            $table->decimal('area_m2', 6, 1)->nullable();
            $table->foreignId('series_apartment_type_id')->nullable()->constrained('series_apartment_types')->nullOnDelete();
            $table->boolean('is_override')->default(false);
            $table->text('override_note')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->unique(['building_id', 'number']);
            $table->unique(['building_id', 'section_id', 'floor', 'position']);
            $table->unique(['building_id', 'id']);
        });
        DB::statement("ALTER TABLE apartments ADD COLUMN retired_numbers integer[] NOT NULL DEFAULT '{}'");
        Pg::tenantFk('apartments', 'section_id', 'sections');
        Pg::enumCheck('apartments', 'side', ['left', 'right'], nullable: true);
        DB::statement('ALTER TABLE apartments ADD CONSTRAINT apartments_shape_check CHECK (number > 0 AND floor > 0 AND position > 0)');

        Schema::create('apartment_cells', function (Blueprint $table): void {
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('section_id');
            $table->smallInteger('floor');
            $table->smallInteger('position');
            $table->unsignedBigInteger('apartment_id');
            $table->boolean('is_primary')->default(true);
            $table->primary(['section_id', 'floor', 'position']);
            $table->index(['building_id', 'apartment_id']);
        });
        DB::statement('CREATE UNIQUE INDEX apartment_cells_primary_unique ON apartment_cells (apartment_id) WHERE is_primary');
        Pg::tenantFk('apartment_cells', 'section_id', 'sections');
        Pg::tenantFk('apartment_cells', 'apartment_id', 'apartments');

        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('apartment_id');
            $table->text('code');
            $table->jsonb('label');
            $table->smallInteger('sort')->default(0);
            $table->smallInteger('radiator_count')->default(1);
            $table->boolean('towel_dryer')->default(false);
            $table->timestampsTz();
            $table->unique(['apartment_id', 'code']);
            $table->unique(['building_id', 'id']);
            $table->index(['building_id', 'apartment_id']);
        });
        Pg::tenantFk('rooms', 'apartment_id', 'apartments', 'CASCADE');

        Schema::create('risers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('section_id');
            $table->text('code');
            $table->text('kind');
            $table->smallInteger('position')->nullable();
            $table->text('side')->nullable();
            $table->smallInteger('floors_from');
            $table->smallInteger('floors_to');
            $table->text('confidence')->default('hypothesis');
            $table->text('source_ref')->nullable();
            $table->foreignId('series_riser_id')->nullable()->constrained('series_risers')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['building_id', 'code']);
            $table->unique(['building_id', 'id']);
            $table->index(['building_id', 'section_id', 'kind']);
        });
        Pg::tenantFk('risers', 'section_id', 'sections');
        Pg::enumCheck('risers', 'kind', ['heating', 'cold_water', 'hot_water', 'hot_water_circulation', 'sewage']);
        Pg::enumCheck('risers', 'confidence', ['hypothesis', 'resident_confirmed', 'manager_confirmed', 'drawing_confirmed']);
        Pg::enumCheck('risers', 'side', ['left', 'right'], nullable: true);

        Schema::create('riser_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('riser_id');
            $table->unsignedBigInteger('apartment_id');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->timestampsTz();
            $table->index(['building_id', 'apartment_id']);
            $table->unique(['building_id', 'id']);
        });
        DB::statement('ALTER TABLE riser_links ADD CONSTRAINT riser_links_unique UNIQUE NULLS NOT DISTINCT (riser_id, apartment_id, room_id)');
        Pg::tenantFk('riser_links', 'riser_id', 'risers', 'CASCADE');
        Pg::tenantFk('riser_links', 'apartment_id', 'apartments', 'CASCADE');
        Pg::tenantFk('riser_links', 'room_id', 'rooms', 'CASCADE');

        Schema::create('building_counters', function (Blueprint $table): void {
            $table->unsignedBigInteger('building_id');
            $table->text('key');
            $table->integer('year');
            $table->integer('value')->default(0);
            $table->primary(['building_id', 'key', 'year']);
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
        });
        Pg::enumCheck('building_counters', 'key', ['manager_report', 'parking_export']);
    }

    public function down(): void
    {
        Schema::dropIfExists('building_counters');
        Schema::dropIfExists('riser_links');
        Schema::dropIfExists('risers');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('apartment_cells');
        Schema::dropIfExists('apartments');
        Schema::dropIfExists('section_land_parcel');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('land_parcels');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('organizations');
    }
};
