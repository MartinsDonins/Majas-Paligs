<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform tables (no building_id): building series catalogue, legal notices, privacy notice versions
 * (MVP-SPEC A.4, PLANNING §4.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_series', function (Blueprint $table): void {
            $table->id();
            $table->text('code')->unique();
            $table->jsonb('name');
            $table->text('wall_material')->nullable();
            $table->integer('years_from')->nullable();
            $table->integer('years_to')->nullable();
            $table->integer('apartments_per_floor_typical')->nullable();
            $table->jsonb('notes')->nullable();
            $table->jsonb('sources')->default('[]');
            $table->text('status')->default('draft');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE building_series ADD COLUMN aliases text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE building_series ADD COLUMN floors_typical integer[] NOT NULL DEFAULT '{}'");
        Pg::enumCheck('building_series', 'status', ['draft', 'published']);

        Schema::create('series_section_layouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id')->constrained('building_series')->cascadeOnDelete();
            $table->text('code');
            $table->smallInteger('apartments_per_floor');
            $table->jsonb('positions');
            $table->boolean('is_default')->default(false);
            $table->jsonb('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['series_id', 'code']);
        });

        Schema::create('series_apartment_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id')->constrained('building_series')->cascadeOnDelete();
            $table->text('code');
            $table->smallInteger('rooms_count');
            $table->decimal('area_m2_typical', 5, 1)->nullable();
            $table->jsonb('label');
            $table->timestampsTz();
            $table->unique(['series_id', 'code']);
        });

        Schema::create('series_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apartment_type_id')->constrained('series_apartment_types')->cascadeOnDelete();
            $table->text('code');
            $table->jsonb('label');
            $table->smallInteger('sort')->default(0);
            $table->smallInteger('radiator_count')->default(1);
            $table->boolean('towel_dryer')->default(false);
            $table->timestampsTz();
            $table->unique(['apartment_type_id', 'code']);
        });
        DB::statement("ALTER TABLE series_rooms ADD CONSTRAINT series_rooms_code_check CHECK (code ~ '^(kitchen|bath|wc|hall|room[1-5])$')");

        Schema::create('series_risers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id')->constrained('building_series')->cascadeOnDelete();
            $table->foreignId('section_layout_id')->nullable()->constrained('series_section_layouts')->cascadeOnDelete();
            $table->text('code_pattern');
            $table->text('kind');
            $table->jsonb('serves');
            $table->text('confidence')->default('hypothesis');
            $table->text('source_ref')->nullable();
            $table->timestampsTz();
            $table->index(['series_id', 'kind']);
        });
        Pg::enumCheck('series_risers', 'kind', ['heating', 'cold_water', 'hot_water', 'hot_water_circulation', 'sewage']);
        Pg::enumCheck('series_risers', 'confidence', ['hypothesis', 'resident_confirmed', 'manager_confirmed', 'drawing_confirmed']);

        Schema::create('legal_notices', function (Blueprint $table): void {
            $table->id();
            $table->text('key');
            $table->text('jurisdiction')->nullable();
            $table->jsonb('body');
            $table->text('source_url')->nullable();
            $table->timestampTz('valid_from');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['key', 'valid_from']);
        });

        Schema::create('privacy_notice_versions', function (Blueprint $table): void {
            $table->id();
            $table->text('kind');
            $table->text('version');
            $table->jsonb('body');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['kind', 'version']);
        });
        DB::statement("ALTER TABLE privacy_notice_versions ADD COLUMN covers_modules text[] NOT NULL DEFAULT '{}'");
        Pg::enumCheck('privacy_notice_versions', 'kind', ['platform_account', 'building_template']);
        DB::statement("ALTER TABLE privacy_notice_versions ADD CONSTRAINT privacy_notice_versions_modules_check CHECK (covers_modules <@ ARRAY['account','core','heating','outages','parking']::text[])");
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_notice_versions');
        Schema::dropIfExists('legal_notices');
        Schema::dropIfExists('series_risers');
        Schema::dropIfExists('series_rooms');
        Schema::dropIfExists('series_apartment_types');
        Schema::dropIfExists('series_section_layouts');
        Schema::dropIfExists('building_series');
    }
};
