<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // The code is what a filter URL is built from, so it is stable by
            // contract: renaming the label must not change every link a
            // customer has shared or a crawler has indexed.
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedInteger('position')->default(0);

            // A shop keeps attributes it does not want in the sidebar — a
            // material it prints on the detail page but nobody filters by.
            $table->boolean('is_filterable')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'position']);
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('product_attributes')->cascadeOnDelete();

            $table->string('value');
            $table->string('slug', 64);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['attribute_id', 'slug']);
            $table->index(['tenant_id', 'attribute_id', 'position']);
        });

        Schema::create('product_attribute_value_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('value_id')->constrained('product_attribute_values')->cascadeOnDelete();

            $table->unique(['product_id', 'value_id']);
            // The listing asks "which products carry this value", so the index
            // leads with the value. Without it every filtered page is a full
            // scan of the pivot.
            $table->index(['tenant_id', 'value_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_value_product');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_attributes');
    }
};
