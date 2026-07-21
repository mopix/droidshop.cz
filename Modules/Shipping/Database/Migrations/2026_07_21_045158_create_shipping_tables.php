<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->enum('provider', ['pickup', 'flat']);
            $table->string('name');
            $table->string('description')->nullable();

            // Price of the delivery itself, in haléře. VAT is carried by the
            // rate, not folded into the amount. `currency` is the shared
            // companion column MoneyCast reads/writes for price and free_from.
            $table->unsignedInteger('price')->default(0);
            $table->string('currency', 3)->default('CZK');
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();

            // Order total (haléře) at or above which this method is free.
            // Null means never free.
            $table->unsignedInteger('free_from')->nullable();

            // Cap in grams; a cart heavier than this cannot pick the method.
            // Null means no weight limit.
            $table->unsignedInteger('max_weight_g')->nullable();

            // Provider config printed on the storefront (pickup address, hours):
            // not secret, plain JSON.
            $table->json('settings')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'position']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->enum('provider', ['cod', 'bank_transfer']);
            $table->string('name');
            $table->string('description')->nullable();

            // A surcharge for using this method (cash on delivery), in haléře.
            // `currency` is MoneyCast's companion column for fee.
            $table->unsignedInteger('fee')->default(0);
            $table->string('currency', 3)->default('CZK');
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();

            // Provider config that can hold a credential (bank account for QR),
            // so it is stored encrypted — see the model's cast.
            $table->text('settings')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'position']);
        });

        Schema::create('shipping_method_payment_method', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipping_method_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // One row per pair. A pair present means "this payment is allowed
            // with this shipping"; no rows for a shipping method at all means
            // every active payment is allowed (see the plan's decision 1).
            $table->unique(['tenant_id', 'shipping_method_id', 'payment_method_id'], 'ship_pay_unique');
            $table->index(['tenant_id', 'shipping_method_id'], 'ship_pay_ship_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_method_payment_method');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('shipping_methods');
    }
};
