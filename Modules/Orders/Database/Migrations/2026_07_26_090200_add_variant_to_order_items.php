<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // No foreign key, exactly like product_id: the variant may be
            // deleted later and the line must stay meaningful. Nullable
            // rather than a 0 sentinel — order_items has no unique index for
            // NULL to defeat, and null reads as "no variant" honestly.
            $table->unsignedBigInteger('variant_id')->nullable()->after('product_id');
            $table->string('variant_label')->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['variant_id', 'variant_label']);
        });
    }
};
