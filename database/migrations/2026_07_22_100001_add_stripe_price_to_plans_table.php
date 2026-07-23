<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            // Stripe Price id (price_...) for this plan's monthly fee, created
            // in the Stripe dashboard and filled in per plan.
            $table->string('stripe_price_id')->nullable()->after('price_year');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('stripe_price_id');
        });
    }
};
