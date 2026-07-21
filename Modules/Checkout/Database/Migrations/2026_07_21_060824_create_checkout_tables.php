<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('token', 64);

            // Not FKs: they point at other modules (customers, shipping)
            // that may be disabled, and a foreign key across a module
            // boundary would make the referenced module undeactivatable.
            // Integrity is kept by the application, not the schema.
            $table->foreignId('customer_id')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->unsignedBigInteger('payment_method_id')->nullable();

            $table->json('meta')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'token']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity');

            // Price snapshot taken at insert time; NOT the pricing authority
            // (see App\Core\Catalog\Contracts\ProductCatalog::price()).
            $table->unsignedInteger('unit_price');
            $table->string('currency', 3)->default('CZK');

            $table->timestamps();

            $table->index(['tenant_id', 'cart_id']);
            $table->unique(['tenant_id', 'cart_id', 'product_id'], 'cart_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
