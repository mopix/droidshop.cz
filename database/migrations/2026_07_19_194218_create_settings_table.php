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
        Schema::create('settings', function (Blueprint $table) {
            // Composite key per spec §15.3: a setting is identified by tenant,
            // module and key, and there is no surrogate id.
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('module');
            $table->string('key');
            $table->json('value');

            $table->timestamps();

            $table->primary(['tenant_id', 'module', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
