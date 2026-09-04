<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_addon_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('label');

            // A group the customer must answer — "which frame" on a picture
            // that is never sold unframed. Checked on the server at add-to-cart
            // time, never only in the form.
            $table->boolean('required')->default(false);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'position']);
        });

        Schema::create('product_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('product_addon_groups')->cascadeOnDelete();

            $table->string('label');
            $table->string('image_path')->nullable();

            // The surcharge, in haléře like every other money column. Zero is
            // ordinary: "bez rámu, 0 Kč" is a real option and the customer has
            // to be able to pick it.
            $table->unsignedInteger('price')->default(0);
            // MoneyCast stores the currency beside the amount, the same way
            // products do: a figure without its currency is a number, not money.
            $table->string('currency', 3)->default('CZK');

            // Its own rate rather than the product's. A frame and a canvas can
            // fall under different rates, and a document that applies one rate
            // to both is wrong in a way the tax office cares about.
            $table->foreignId('tax_rate_id')->constrained('tax_rates');

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'group_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_addons');
        Schema::dropIfExists('product_addon_groups');
    }
};
