<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parcels handed to a carrier (wave 2.5).
 *
 * order_id is deliberately not a foreign key: orders belongs to another
 * module, the same boundary carts.shipping_method_id keeps.
 *
 * `submitting` (fix round 1/5) is a transient claim state: exactly one live
 * request may flip a row from `pending`/`failed` into it, via a single atomic
 * `UPDATE ... WHERE status IN ('pending','failed')` — see
 * Modules\Packeta\Services\ShipmentSubmitter::claimForSubmission(). Without
 * it, two requests racing the same order (double click, two tabs, a retry
 * overlapping a slow first attempt) could both adopt the same `pending` row
 * and both call the carrier, producing two real parcels for one order.
 *
 * `claimed_at` (fix round 2/5) is the timestamp that claim writes. A row a
 * process crashed on between winning the claim and writing the carrier's
 * answer would otherwise sit in `submitting` forever — reachable by nothing,
 * an order that silently never ships — so claimForSubmission() also reclaims
 * a `submitting` row once `claimed_at` is older than
 * config('packeta.submit_stale_after_minutes').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('order_id');

            $table->string('carrier', 20);
            $table->string('packet_id', 40)->nullable();
            $table->string('barcode', 60)->nullable();

            $table->enum('status', ['pending', 'submitting', 'submitted', 'failed', 'cancelled'])->default('pending');

            $table->unsignedInteger('cod_amount')->default(0);
            $table->string('currency', 3)->default('CZK');
            $table->unsignedInteger('weight_grams')->default(0);

            $table->text('error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('label_printed_at')->nullable();

            $table->timestamps();

            // One parcel per order. Without this a double click bills the
            // tenant for two parcels and prints two labels for one box.
            $table->unique(['tenant_id', 'order_id'], 'shipment_order_unique');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
