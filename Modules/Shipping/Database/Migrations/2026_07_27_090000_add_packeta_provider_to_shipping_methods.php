<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // The narrowed enum below rejects 'packeta' outright, so any row
        // still using it must go first or the ALTER itself would fail. This
        // is destructive for a tenant who actually configured Packeta
        // shipping — surviving cart_items.shipping_method_id references have
        // no FK, so they are left dangling. Log the blast radius rather than
        // deleting silently (CLAUDE.md: no silent destructive DB ops).
        $affected = DB::table('shipping_methods')->where('provider', 'packeta')->count();

        if ($affected > 0) {
            Log::warning(
                "Rolling back packeta shipping provider: deleting {$affected} shipping_methods row(s) with provider='packeta'. ".
                'This is destructive: any tenant using Packeta shipping loses that method, and orphaned '.
                'cart_items.shipping_method_id references (no FK) may remain.',
                ['affected_rows' => $affected]
            );
        }

        DB::table('shipping_methods')->where('provider', 'packeta')->delete();

        DB::statement("ALTER TABLE shipping_methods MODIFY provider ENUM('pickup', 'flat') NOT NULL");
    }
};
