<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shop-wide variant display moves from tenant_theme to the products
 * module's settings (wave 2.10). It only sat next to the logo because no module
 * settings screen existed yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_theme', 'variant_display')) {
            return;
        }

        // Carry the choice over before the column goes: a shop that picked
        // dropdowns must not silently flip back to radios on deploy.
        DB::table('tenant_theme')
            ->whereNotNull('variant_display')
            ->orderBy('tenant_id')
            ->each(function (object $row): void {
                DB::table('settings')->updateOrInsert(
                    ['tenant_id' => $row->tenant_id, 'module' => 'products', 'key' => 'variant_display'],
                    ['value' => json_encode($row->variant_display), 'created_at' => now(), 'updated_at' => now()],
                );
            });

        Schema::table('tenant_theme', function (Blueprint $table): void {
            $table->dropColumn('variant_display');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_theme', function (Blueprint $table): void {
            $table->string('variant_display', 16)->nullable();
        });

        DB::table('settings')
            ->where('module', 'products')
            ->where('key', 'variant_display')
            ->orderBy('tenant_id')
            ->each(function (object $row): void {
                DB::table('tenant_theme')
                    ->where('tenant_id', $row->tenant_id)
                    ->update(['variant_display' => json_decode($row->value, true)]);
            });
    }
};
