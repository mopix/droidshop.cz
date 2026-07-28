<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The time series of prices a product was actually sold at — the
        // evidence behind "lowest price in the last 30 days" (§ 12a of the
        // consumer protection act). A closed row is never rewritten:
        // falsified history is worse than missing history.
        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()
                ->constrained('product_variants')->cascadeOnDelete();

            $table->unsignedBigInteger('price');
            $table->string('currency', 3)->default('CZK');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'product_id', 'variant_id', 'starts_at'], 'price_history_lookup');
            $table->index(['tenant_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_history');
    }
};
