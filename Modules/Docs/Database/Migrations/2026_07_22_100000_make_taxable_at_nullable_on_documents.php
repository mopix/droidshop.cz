<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A proforma is not a tax document and carries no DUZP (spec §16.6,
     * wave 1.6 Stage 4) — ProformaSnapshot::for() sets taxable_at to null.
     * The original create_documents_table migration (wave 1.5, already
     * shipped) declared the column NOT NULL because only invoices existed
     * at the time. Added as its own alter, same reasoning as the
     * 2026_07_22_090000 correction-columns migration: that migration has
     * shipped, so every change this stage needs arrives as an alter, not a
     * rewrite of history.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->date('taxable_at')->nullable()->change();
        });
    }

    /**
     * Caveat: this fails if any proforma (or other row with a null
     * taxable_at) exists — NOT NULL cannot hold it. Rolling back this
     * migration on a database that has already issued a proforma requires
     * backfilling or deleting those rows first.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->date('taxable_at')->nullable(false)->change();
        });
    }
};
