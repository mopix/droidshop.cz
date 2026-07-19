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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');

            // Amounts are integers in haléře (spec §15.1) — floats are banned.
            $table->unsignedInteger('price_month')->default(0);
            $table->unsignedInteger('price_year')->default(0);

            $table->enum('level', ['base', 'premium'])->default('base');
            $table->boolean('is_public')->default(true);

            // {"products": 500, "storage_mb": 2048, "emails_month": 3000}
            $table->json('limits');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
