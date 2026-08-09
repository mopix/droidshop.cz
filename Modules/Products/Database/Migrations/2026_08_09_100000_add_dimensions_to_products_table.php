<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product dimensions (wave 3.8).
 *
 * Millimetres as integers, for the same reason prices are haléře: a float on
 * a measurement drifts, and these end up in a carrier's API where a rounding
 * difference decides whether a parcel is oversized.
 *
 * Nullable throughout. Most shops will never fill them in, and a default of
 * zero would tell the carrier the parcel is flat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('length_mm')->nullable()->after('weight_g');
            $table->unsignedInteger('width_mm')->nullable()->after('length_mm');
            $table->unsignedInteger('height_mm')->nullable()->after('width_mm');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['length_mm', 'width_mm', 'height_mm']);
        });
    }
};
