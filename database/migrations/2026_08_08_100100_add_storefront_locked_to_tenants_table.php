<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the shop is behind a password, on the tenant row itself (wave 3.6).
 *
 * The flag is duplicated from shop_settings.locked, which stays the setting
 * the merchant edits and the place the password hash lives. The copy exists
 * because this flag is read on EVERY request, by EnsureShopUnlocked and by
 * PageCachePolicy, including requests a warm page cache would otherwise
 * answer without touching the database at all. Reading it from shop_settings
 * cost two extra queries on every cache hit and broke the query budget wave
 * 3.0 set for exactly this reason.
 *
 * Same argument that put the page-cache generation counters here:
 * DomainTenantFinder already loads this row on every request, so a column on
 * it is free. ShopSettingsService is the only writer and keeps the two in
 * step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('storefront_locked')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('storefront_locked');
        });
    }
};
