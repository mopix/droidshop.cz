<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 191);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug', 191);
            $table->string('short_description', 240)->nullable();
            $table->longText('description')->nullable();
            $table->string('status', 16)->default('draft');

            // Gross price in haléře, plus the rate it was quoted at. Net is
            // derived, never stored: two stored figures drift the moment a
            // rate changes, and the shelf price is the one the customer sees.
            $table->foreignId('tax_rate_id')->constrained('tax_rates')->restrictOnDelete();
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('compare_at_price')->nullable();
            $table->unsignedBigInteger('purchase_price')->nullable();
            $table->string('currency', 3)->default('CZK');

            $table->string('sku', 64)->nullable();
            $table->string('ean', 14)->nullable();
            $table->foreignId('manufacturer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('weight_g')->default(0);

            $table->boolean('stock_tracked')->default(false);
            $table->integer('stock_qty')->default(0);
            $table->string('stock_policy', 24)->default('show_sold_out');
            $table->unsignedInteger('stock_alert_qty')->nullable();

            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('seo_image_path')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'sku']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('path');
            $table->string('alt')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_main')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'position']);
        });

        Schema::create('product_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // The link goes when a category is deleted; the product stays.
            // Losing a product because a grouping was reorganised would be the
            // worst possible outcome of an admin tidying up.
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_primary')->default(false);

            $table->unique(['product_id', 'category_id']);
            $table->index(['tenant_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_category');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('manufacturers');
    }
};
