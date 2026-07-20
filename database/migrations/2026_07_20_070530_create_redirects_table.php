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
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // 191 is the InnoDB utf8mb4 index ceiling and the same limit slugs
            // are validated against.
            $table->string('from_path', 191);
            $table->string('to_path', 191);
            $table->unsignedSmallInteger('status')->default(301);

            // Records why the row exists (a category rename, a product slug
            // change) so a confusing redirect can be traced back.
            $table->string('reason', 64)->nullable();

            $table->timestamps();

            // One destination per source: a path cannot redirect two ways.
            $table->unique(['tenant_id', 'from_path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
