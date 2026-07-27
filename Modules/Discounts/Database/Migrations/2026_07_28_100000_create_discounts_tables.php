<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // NULL = an automatic rule that needs no code. Both MySQL and
            // SQLite treat NULLs in a unique index as always distinct, which
            // is exactly what we want: many rules, at most one row per code.
            $table->string('code', 64)->nullable();
            $table->boolean('active')->default(true);

            $table->string('type', 20);              // percent | fixed | free_shipping
            $table->unsignedInteger('value')->default(0);   // permille | haléře | 0
            $table->string('currency', 3)->default('CZK');
            $table->string('scope', 20)->default('cart');   // cart | categories | products

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('min_cart_total')->nullable();

            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_email')->nullable();
            $table->unsignedInteger('used_count')->default(0);

            $table->boolean('requires_login')->default(false);
            $table->boolean('first_order_only')->default(false);
            $table->boolean('combinable')->default(true);
            $table->unsignedInteger('priority')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('discount_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();

            // Not FKs: they point at the categories/products modules, which a
            // tenant may switch off. A cross-module foreign key would make the
            // referenced module undeactivatable (same stance as carts.shipping_method_id).
            $table->string('target_type', 20);        // category | product
            $table->unsignedBigInteger('target_id');

            $table->timestamps();

            $table->unique(['tenant_id', 'discount_id', 'target_type', 'target_id'], 'discount_target_unique');
        });

        Schema::create('discount_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('order_id');
            $table->string('email');                  // always lowercased before write
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedInteger('amount')->default(0);
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            // One redemption row per (discount, order): a retried submit that
            // resolves to the same order must not consume the allowance twice.
            $table->unique(['tenant_id', 'discount_id', 'order_id'], 'discount_redemption_unique');
            $table->index(['tenant_id', 'discount_id', 'email'], 'discount_redemption_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_redemptions');
        Schema::dropIfExists('discount_targets');
        Schema::dropIfExists('discounts');
    }
};
