<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Adds the packeta_hd provider — Zásilkovna delivering to the shopper's own
 * address through a partner carrier, alongside the existing branch-delivery
 * `packeta` (Packeta home-delivery wave).
 *
 * Same shape as 2026_07_27_090000_add_packeta_provider_to_shipping_methods:
 * a raw ALTER because Laravel has no portable enum-widening primitive and
 * doctrine/dbal is not installed, MySQL-only because SQLite stores enums as
 * plain text and already accepts the new value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE shipping_methods MODIFY provider ENUM('pickup', 'flat', 'packeta', 'packeta_hd') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Destructive for a tenant who actually configured Packeta home
        // delivery — surviving cart_items.shipping_method_id references have
        // no FK, so they are left dangling. Log the blast radius rather than
        // deleting silently (CLAUDE.md: no silent destructive DB ops).
        $affected = DB::table('shipping_methods')->where('provider', 'packeta_hd')->count();

        if ($affected > 0) {
            Log::warning(
                "Rolling back packeta_hd shipping provider: deleting {$affected} shipping_methods row(s) with provider='packeta_hd'. ".
                'This is destructive: any tenant using Packeta home delivery loses that method, and orphaned '.
                'cart_items.shipping_method_id references (no FK) may remain.',
                ['affected_rows' => $affected]
            );
        }

        DB::table('shipping_methods')->where('provider', 'packeta_hd')->delete();

        DB::statement("ALTER TABLE shipping_methods MODIFY provider ENUM('pickup', 'flat', 'packeta') NOT NULL");
    }
};
