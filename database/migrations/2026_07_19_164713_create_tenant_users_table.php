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
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // MVP ships owner only; permissions JSON exists from day one so the
            // staff role in phase 2 needs no schema change (spec §15.4).
            $table->enum('role', ['owner', 'staff'])->default('owner');
            $table->json('permissions')->nullable();

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();

            $table->primary(['tenant_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
