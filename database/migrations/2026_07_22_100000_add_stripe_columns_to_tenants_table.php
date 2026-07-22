<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // Stripe object ids for the subscription. The webhook resolves a
            // tenant from customer id; the sweeper skips tenants that have a
            // subscription id (Stripe owns their lifecycle).
            $table->string('stripe_customer_id')->nullable()->after('billing_name')->index();
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['stripe_customer_id', 'stripe_subscription_id']);
        });
    }
};
