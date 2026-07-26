<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_theme', function (Blueprint $table) {
            // Shop-wide default for how a product's variant axes are shown.
            // Lives here rather than in module settings because there is no
            // admin surface for SettingsService yet (see the wave 2.4 spec);
            // it moves once one exists.
            $table->string('variant_display', 16)->default('radio')->after('accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_theme', function (Blueprint $table) {
            $table->dropColumn('variant_display');
        });
    }
};
