<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'position']);
            $table->unique(['product_id', 'name']);
        });

        Schema::create('product_option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('product_options')->cascadeOnDelete();
            $table->string('value', 60);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'option_id', 'position']);
            $table->unique(['option_id', 'value']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('sku', 64)->nullable();
            $table->string('ean', 14)->nullable();

            // Nullable on purpose: null means "inherit products.price"
            // (design decision — absolute price with fallback). A zero would
            // be a real price of zero, which is a different statement.
            $table->unsignedBigInteger('price')->nullable();
            $table->string('currency', 3)->default('CZK');

            $table->boolean('stock_tracked')->default(false);
            $table->integer('stock_qty')->default(0);
            $table->string('stock_policy', 24)->default('show_sold_out');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'position']);
            $table->index(['tenant_id', 'sku']);
        });

        Schema::create('product_variant_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('option_value_id')->constrained('product_option_values')->cascadeOnDelete();

            $table->unique(['variant_id', 'option_value_id']);
            $table->index(['tenant_id', 'option_value_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            // null = inherit the shop-wide default from tenant_theme.
            $table->string('variant_display', 16)->nullable()->after('weight_g');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('variant_display');
        });

        Schema::dropIfExists('product_variant_values');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_option_values');
        Schema::dropIfExists('product_options');
    }
};
