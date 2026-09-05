<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_theme', function (Blueprint $table) {
            // Which storefront theme this shop runs. The key of a directory in
            // themes/, never a path — the value reaches a view finder, and a
            // column that could hold a path would be a traversal waiting to
            // happen.
            //
            // Defaulted in the migration rather than in the model so every
            // existing row means "the look this shop already has" the moment
            // the column exists. A shop must not change appearance because
            // the platform grew a feature.
            $table->string('template', 32)->default('base')->after('accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_theme', function (Blueprint $table) {
            $table->dropColumn('template');
        });
    }
};
