<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the packeta provider (wave 2.5).
 *
 * The provider column was created as an enum in wave 1.3, so a new carrier
 * genuinely needs a schema change — the model's original comment claiming
 * otherwise was wrong. Raw ALTER rather than a Blueprint change: Laravel has
 * no portable enum-widening primitive, and doctrine/dbal is not installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            // SQLite stores enums as plain text and accepts the new value as
            // it is; nothing to widen.
            return;
        }

        DB::statement("ALTER TABLE shipping_methods MODIFY provider ENUM('pickup', 'flat', 'packeta') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('shipping_methods')->where('provider', 'packeta')->delete();

        DB::statement("ALTER TABLE shipping_methods MODIFY provider ENUM('pickup', 'flat') NOT NULL");
    }
};
