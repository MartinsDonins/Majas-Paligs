<?php

declare(strict_types=1);

use Database\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADMIN-007 / SEC-011 — resident roster "Iedzīvotāju saraksts" (ROUND2-SPEC §5.1, decisions D1/D2).
 *
 * Admin-only contact register of the house (house = controller). Names, phones, e-mail and the comment exist only
 * as ContactVault ciphertext (XChaCha20-Poly1305, AD bound to building + table + row + field); phones and the e-mail
 * also get per-building blind indexes (exact-match dedup, account linking, GDPR export / erasure). No plaintext
 * personal column anywhere. Gate L-R (RosterGate) is enforced in the policies and by the model guard.
 *
 * The `roster` module joins the notice CHECKs in 000110 (GOV-012, D2), which runs before this migration.
 * Retention grants (prune:roster): majas_retention SELECT + DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('apartment_id');
            $table->text('relation')->default('contact');
            $table->binary('name_ciphertext')->nullable();
            $table->binary('phones_ciphertext')->nullable();
            $table->binary('email_ciphertext')->nullable();
            $table->binary('email_bidx')->nullable();
            $table->boolean('bis_registered')->nullable();
            $table->boolean('whatsapp_member')->nullable();
            $table->binary('comment_ciphertext')->nullable();
            $table->smallInteger('key_version')->default(1);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('source')->default('admin');
            $table->ulid('import_run_id')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('moved_out_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign('building_id')->references('id')->on('buildings')->restrictOnDelete();
            $table->index(['building_id', 'apartment_id']);
            $table->index(['building_id', 'email_bidx']);
            $table->index(['building_id', 'user_id']);
            $table->unique(['building_id', 'id']);
        });
        DB::statement("ALTER TABLE resident_contacts ADD COLUMN phone_bidx bytea[] NOT NULL DEFAULT '{}'");
        Pg::enumCheck('resident_contacts', 'relation', ['owner', 'tenant', 'contact']);
        Pg::enumCheck('resident_contacts', 'source', ['import', 'admin', 'elder']);
        DB::statement('ALTER TABLE resident_contacts ADD CONSTRAINT resident_contacts_something_check CHECK (name_ciphertext IS NOT NULL OR phones_ciphertext IS NOT NULL OR email_ciphertext IS NOT NULL)');
        DB::statement('ALTER TABLE resident_contacts ADD CONSTRAINT resident_contacts_email_bidx_check CHECK ((email_bidx IS NULL) = (email_ciphertext IS NULL) AND (email_bidx IS NULL OR octet_length(email_bidx) = 32))');
        DB::statement('ALTER TABLE resident_contacts ADD CONSTRAINT resident_contacts_phones_check CHECK (cardinality(phone_bidx) <= 3 AND (phones_ciphertext IS NOT NULL OR cardinality(phone_bidx) = 0))');
        DB::statement('ALTER TABLE resident_contacts ADD CONSTRAINT resident_contacts_key_version_check CHECK (key_version >= 1)');
        DB::statement('CREATE INDEX resident_contacts_phone_bidx ON resident_contacts USING gin (phone_bidx)');
        Pg::tenantFk('resident_contacts', 'apartment_id', 'apartments');
        DB::statement('ALTER TABLE resident_contacts ADD CONSTRAINT resident_contacts_import_run_tenant_fk FOREIGN KEY (building_id, import_run_id)
            REFERENCES import_runs (building_id, id) ON DELETE SET NULL (import_run_id)');

        DB::unprepared(<<<'SQL'
GRANT SELECT, DELETE ON resident_contacts TO majas_retention;
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS resident_contacts');
    }
};
