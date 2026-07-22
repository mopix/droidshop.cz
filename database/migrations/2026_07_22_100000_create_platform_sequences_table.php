<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Non-tenant counter for platform-issued documents (subscription
        // invoices). Deliberately separate from `sequences`, which is keyed by
        // tenant_id and would need a sentinel row here.
        Schema::create('platform_sequences', function (Blueprint $table) {
            $table->string('series')->primary();
            $table->unsignedBigInteger('next_number')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_sequences');
    }
};
