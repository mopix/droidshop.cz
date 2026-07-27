<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The only thing a shopper's cart ever remembers about a discount: the code
 * they typed. Everything else — whether it is still valid, how much it is
 * worth, which lines it touches — is recomputed on every render (spec §16.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('coupon_code', 64)->nullable()->after('payment_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('coupon_code');
        });
    }
};
