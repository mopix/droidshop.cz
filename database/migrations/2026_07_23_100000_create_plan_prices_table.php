<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('interval'); // month | year
            $table->string('stripe_price_id')->nullable();
            $table->unsignedBigInteger('price_amount'); // haléře, gross
            $table->char('currency', 3)->default('CZK');
            $table->timestamps();
            $table->unique(['plan_id', 'interval']);
        });

        // Data migrace: existující měsíční cena a price id → řádek interval=month.
        if (Schema::hasColumn('plans', 'stripe_price_id')) {
            foreach (DB::table('plans')->get() as $plan) {
                DB::table('plan_prices')->insert([
                    'plan_id' => $plan->id,
                    'interval' => 'month',
                    'stripe_price_id' => $plan->stripe_price_id,
                    'price_amount' => $plan->price_month,
                    'currency' => 'CZK',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
