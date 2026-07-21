<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gateway's own transaction identifier (Comgate transId), stored on the
 * order so a later verify() — from the browser return or the webhook — knows
 * which transaction to ask about, and the admin can see it (plan decision 6).
 *
 * Owned by the payments module: the orders module has no reason to know a
 * gateway reference exists. Nullable — offline orders (cash on delivery, bank
 * transfer) never get one. The tenant context travels through the order row,
 * so no separate tenant_id is needed on this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_reference')->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('payment_reference');
        });
    }
};
