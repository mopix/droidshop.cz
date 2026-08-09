<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns the prices tab needed (wave 3.9).
 *
 * `purchase_tax_rate_id` — a supplier may charge a different rate than the
 * shop sells at (imports, goods moved between rates), and a purchase price
 * converted with the selling rate produces a margin that is quietly wrong.
 * Nullable: empty inherits the product's own rate.
 *
 * `sale_percent` — the discount as the merchant thinks of it. Stored, not
 * merely used to compute an amount once: with it stored, raising the shelf
 * price keeps the discount at the percentage that was agreed, instead of
 * silently turning 20 % off into 12 % off. The amount in `sale_price` stays
 * the authority everything else reads (catalogue, documents, the 30-day
 * lowest-price history from wave 2.7).
 *
 * 1–99. A hundred per cent is "free", which is a different tool — the
 * discount engine settles a zero-koruna order without a gateway — and zero is
 * not a discount at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('purchase_tax_rate_id')->nullable()->after('purchase_price')
                ->constrained('tax_rates')->nullOnDelete();

            $table->unsignedTinyInteger('sale_percent')->nullable()->after('sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_tax_rate_id');
            $table->dropColumn('sale_percent');
        });
    }
};
