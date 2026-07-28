<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Gross sale price in haléře, in the product's own currency and at
            // its own VAT rate. Null means "no sale", which is a different
            // statement from a sale of zero.
            $table->unsignedBigInteger('sale_price')->nullable()->after('price');

            // The window lives on the product only: one campaign per product,
            // amounts per variant. Two independent windows would allow a
            // variant on sale while its product is not.
            $table->timestamp('sale_starts_at')->nullable()->after('sale_price');
            $table->timestamp('sale_ends_at')->nullable()->after('sale_starts_at');

            // Dead since it was added: nothing on the storefront ever read it.
            // Two "original price" fields side by side is a trap.
            $table->dropColumn('compare_at_price');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            // Absolute amount, never a percentage of the product's sale.
            $table->unsignedBigInteger('sale_price')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sale_price', 'sale_starts_at', 'sale_ends_at']);
            $table->unsignedBigInteger('compare_at_price')->nullable()->after('price');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('sale_price');
        });
    }
};
