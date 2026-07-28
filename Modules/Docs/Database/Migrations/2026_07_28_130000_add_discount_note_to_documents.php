<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wave's binding decision on how a discount shows up on a tax document
 * (rozhodnutí 2026-07-28): the line items already carry discounted amounts
 * (order_items.line_total), so no negative line or separate discount row is
 * added here — just an informational note under the item table naming what
 * was applied. `discount_total` backs the note's money and is never an input
 * to any total (documents.total is unaffected); `discount_note` is null on
 * every document that carries no discount, which is also how an existing
 * undiscounted invoice keeps rendering unchanged.
 *
 * Both columns are generic on `documents`, not invoice-only: a credit note or
 * proforma snapshot that never sets them simply gets the column defaults
 * (0 / null), so InvoiceIssuer is the only builder that has to know about
 * this wave's addition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('discount_total')->default(0)->after('total');
            $table->string('discount_note', 500)->nullable()->after('discount_total');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['discount_total', 'discount_note']);
        });
    }
};
