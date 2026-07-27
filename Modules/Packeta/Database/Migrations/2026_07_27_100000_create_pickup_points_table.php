<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared pickup point catalogue (wave 2.5).
 *
 * Deliberately without tenant_id: every shop delivering to Zásilkovna resolves
 * the very same points, so one platform-wide sync feeds all of them — the same
 * class of table as plans or tax_rates. Listed in
 * SchemaConventionTest::PLATFORM_TABLES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_points', function (Blueprint $table) {
            $table->id();

            $table->string('carrier', 20);
            $table->string('code', 40);

            $table->string('name');
            $table->string('street')->default('');
            $table->string('city')->default('');
            $table->string('zip', 10)->default('');
            $table->char('country', 2)->default('CZ');

            // Diacritics stripped and lowercased at write time, so a LIKE can
            // match "zabovresky" against "Žabovřesky" — the same normalisation
            // products.search_text uses (rozhodnutí 2026-07-20). InnoDB
            // fulltext is not an option: it handles neither Czech inflection
            // nor SQLite in tests.
            $table->string('search_text', 512)->default('');

            $table->json('opening_hours')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['carrier', 'code']);
            $table->index(['carrier', 'country', 'zip']);
            $table->index('search_text');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_points');
    }
};
