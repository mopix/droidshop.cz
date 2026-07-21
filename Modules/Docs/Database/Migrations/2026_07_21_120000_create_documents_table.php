<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->enum('type', ['invoice', 'proforma', 'credit_note'])->default('invoice');
            $table->string('number');
            $table->string('series');
            $table->timestamp('issued_at');
            $table->date('taxable_at');       // DUZP
            $table->date('due_at');
            $table->json('supplier');
            $table->json('customer');
            $table->json('items');
            $table->json('vat_summary');
            $table->unsignedBigInteger('total'); // haléře (Money)
            $table->char('currency', 3)->default('CZK');
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            // One document per (order, type) — the DB-level idempotency guard,
            // not just an in-code check: a concurrent double-issue loses here.
            $table->unique(['tenant_id', 'order_id', 'type']);
            $table->index(['tenant_id', 'issued_at']); // CSV export za období (vlna 1.6)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
