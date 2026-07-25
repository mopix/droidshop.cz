<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedInteger('position')->default(0);
            $table->string('type', 32);
            $table->json('payload');
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_blocks');
    }
};
