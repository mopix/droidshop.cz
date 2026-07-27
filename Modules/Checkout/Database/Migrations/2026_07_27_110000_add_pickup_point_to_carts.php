<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pickup point a shopper chose (wave 2.5).
 *
 * A carrier code, not a foreign key: the catalogue is platform-wide and
 * resynced daily, so rows come and go, while the carrier's own code is stable.
 * Whether the code still resolves to an active point is re-checked when the
 * order is placed, not enforced by the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('pickup_point_code', 40)->nullable()->after('shipping_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('pickup_point_code');
        });
    }
};
