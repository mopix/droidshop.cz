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
        Schema::create('sequences', function (Blueprint $table) {
            // Composite key per spec §15.3: one counter per (tenant, series).
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('series');
            $table->string('prefix')->default('');
            $table->unsignedBigInteger('next_number')->default(1);

            $table->primary(['tenant_id', 'series']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
