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
        Schema::create('modules', function (Blueprint $table) {
            // The manifest name is the identity of a module, so it is the key.
            $table->string('key')->primary();

            $table->string('version');
            $table->boolean('core')->default(false);
            $table->enum('level', ['base', 'premium'])->default('base');

            // Kill switch (spec §15.5): flipping this off unregisters the
            // module's routes and listeners for every tenant at once.
            $table->boolean('enabled_globally')->default(true);

            // Full manifest, so the registry can answer without touching disk.
            $table->json('manifest');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
