<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per feed a shop runs. A missing row means the feed was never
        // switched on, which is the same answer as disabled — the controller
        // treats both as 404.
        Schema::create('product_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->boolean('enabled')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'type']);
        });

        // The comparison shopper's own taxonomy, per category and per feed.
        // A separate table rather than columns on `categories`: this module can
        // be switched off and must not leave columns behind in another one.
        Schema::create('feed_category_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('category_text', 500);
            $table->timestamps();

            $table->unique(['tenant_id', 'category_id', 'type'], 'feed_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_category_mappings');
        Schema::dropIfExists('product_feeds');
    }
};
