<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->uuid('uuid');
            $table->string('number');

            // Not FKs: customers and checkout are separate modules that may be
            // disabled, and a foreign key across a module boundary would make
            // the referenced module undeactivatable (see cart_items' own
            // comment on the same trade-off).
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('cart_id')->nullable();

            // Never autoincrement: this is the idempotency key that lets a
            // retried checkout submit safely return the order already placed
            // instead of a duplicate.
            $table->string('checkout_token', 64);

            $table->string('source')->default('storefront'); // storefront | manual
            $table->string('email');
            $table->string('phone')->nullable();

            $table->json('billing');
            $table->json('shipping')->nullable();

            // Snapshots of the shipping/payment method as they were at the
            // moment of placement — the method itself may change price or
            // be deleted later without altering history.
            $table->json('shipping_snapshot')->nullable();
            $table->json('payment_snapshot')->nullable();

            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('shipping_total')->default(0);
            $table->unsignedInteger('payment_fee')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->string('currency', 3)->default('CZK');

            $table->json('vat_summary')->nullable();

            $table->string('fulfillment_status')->default('new');
            $table->string('payment_status')->default('unpaid');

            $table->string('note')->nullable();
            $table->timestamp('placed_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'uuid']);
            $table->unique(['tenant_id', 'cart_id', 'checkout_token'], 'order_idem_unique');
            $table->index(['tenant_id', 'fulfillment_status']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Not a foreign key on purpose: the products module may be off, or
            // the product may since have been deleted (AK 12). The columns
            // below are a full snapshot, so the row stays meaningful either
            // way — losing the product must never lose the order line.
            $table->unsignedBigInteger('product_id')->nullable();

            $table->string('name');
            $table->string('sku')->nullable();

            $table->unsignedInteger('unit_price');
            // VAT rate at the moment of purchase, as a percentage (e.g. 21.00)
            // — not a tax_rate_id, because a rate the accountant later edits or
            // a rate row that gets deleted must not reach back into history.
            $table->decimal('tax_rate', 5, 2);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('line_total');
            $table->string('currency', 3)->default('CZK');

            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // system | admin | customer — who caused this event.
            $table->string('actor_type', 16);
            // Not a foreign key: an admin is a platform user, a customer comes
            // from a separate module, and a system event has no actor at all.
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->string('type');
            $table->string('from')->nullable();
            $table->string('to')->nullable();
            $table->string('note')->nullable();

            // Never a password or payment credential — see the model's
            // docblock for the rule this column has to honour.
            $table->json('payload')->nullable();

            // Append-only log: entries are never edited, so no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
