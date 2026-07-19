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
        Schema::create('jobs_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();

            $table->string('type');
            $table->enum('status', ['pending', 'running', 'finished', 'failed'])->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('report')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs_log');
    }
};
