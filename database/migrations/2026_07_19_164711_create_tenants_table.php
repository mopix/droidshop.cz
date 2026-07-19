<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');

            $table->enum('status', [
                'trial', 'active', 'past_due', 'suspended', 'pending_deletion', 'deleted',
            ])->default('trial');

            // Nullable: a tenant exists before a plan is picked during onboarding.
            $table->foreignId('plan_id')->nullable()->constrained('plans')->restrictOnDelete();

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();

            $table->string('billing_name')->nullable();
            $table->string('billing_ico', 16)->nullable();
            $table->string('billing_dic', 16)->nullable();
            $table->json('billing_address')->nullable();
            $table->boolean('vat_payer')->default(false);

            $table->char('country', 2)->default('CZ');
            $table->char('currency', 3)->default('CZK');

            $table->timestamps();

            // The scheduler sweeps tenants by status (trial expiry, dunning, purge).
            $table->index(['status', 'trial_ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
