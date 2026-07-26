<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            // NOT NULL with a 0 sentinel, not nullable: in both MySQL and
            // SQLite every NULL is distinct inside a unique index, so a
            // nullable column would let the same variant-less product be
            // inserted as several rows — exactly what cart_item_unique
            // exists to prevent.
            $table->unsignedBigInteger('variant_id')->default(0)->after('product_id');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('cart_item_unique');
            $table->unique(['tenant_id', 'cart_id', 'product_id', 'variant_id'], 'cart_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('cart_item_unique');
            $table->unique(['tenant_id', 'cart_id', 'product_id'], 'cart_item_unique');
            $table->dropColumn('variant_id');
        });
    }
};
