<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();

            $table->string('slug');
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('is_published')->default(false);

            // SEO fields, per the storefront rendering rule.
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();

            $table->timestamps();

            // Slugs are unique within a shop, not across the platform: two
            // tenants may both have /stranka/kontakt.
            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
