<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // PF{YYYY}{NNNN}, non-tenant series

            // Customer = the tenant we bill. restrictOnDelete: an issued invoice
            // pins its tenant (accounting record must not dangle).
            $table->foreignId('billed_tenant_id')->constrained('tenants')->restrictOnDelete();

            $table->json('supplier'); // snapshot of platform identity at issue time
            $table->json('customer');  // snapshot of tenant.billing_* at issue time

            $table->string('plan_key');
            $table->timestamp('period_from');
            $table->timestamp('period_to');

            // Money in haléře.
            $table->unsignedBigInteger('subtotal');
            $table->unsignedTinyInteger('vat_rate');
            $table->unsignedBigInteger('vat_amount');
            $table->unsignedBigInteger('total');
            $table->json('vat_summary');

            $table->timestamp('issued_at');
            $table->timestamp('taxable_at'); // DUZP
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index('billed_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoices');
    }
};
