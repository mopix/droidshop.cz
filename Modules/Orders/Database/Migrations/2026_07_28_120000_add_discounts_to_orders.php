<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a discount did to an order.
 *
 * `line_total` stays the amount actually charged for the line — already net of
 * its share of the discount — so the VAT recapitulation, the invoice and the
 * credit note all keep reading exactly one number (rozhodnutí 2026-07-28).
 * `discount_total` is what came off, kept for display and for the invoice
 * note, never as an input to any total. Both columns are unsigned, which is
 * also the hard backstop against an over-allocated line: a negative charge is
 * not representable, so it fails loudly instead of quietly reversing money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('discount_total')->default(0)->after('items_total');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('discount_total')->default(0)->after('line_total');
        });

        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // A snapshot, not a reference — and deliberately not a foreign key
            // either: discounts is a separate module a tenant may switch off,
            // and the coupon itself may since have been deleted. The order has
            // to survive both, exactly like order_items.variant_label survives
            // a deleted variant (rozhodnutí 2026-07-26).
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->string('type', 20);
            // What the discount was worth on THIS order. No currency column of
            // its own: an order is settled in one currency, carried on
            // orders.currency, and a second copy could only ever disagree.
            $table->unsignedInteger('amount')->default(0);
            $table->boolean('free_shipping')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_discounts');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('discount_total');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('discount_total');
        });
    }
};
