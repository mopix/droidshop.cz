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
        Schema::create('tenant_theme', function (Blueprint $table) {
            $table->id();

            // One theme per tenant: constrained()->unique() both scopes the
            // row to a tenant and gives the schema-convention test its
            // required tenant_id-leading index.
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete()->unique();

            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('primary_color', 9)->nullable();
            $table->string('accent_color', 9)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_theme');
    }
};
