<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('name');
            $table->string('slug', 191);

            // Materialised path of ancestor ids, "/3/17/". Depth is capped at
            // four levels (spec §16.2), so a recursive CTE would buy nothing
            // over a LIKE on this column — and this one stays readable in a
            // query log.
            $table->string('path', 191)->default('/');
            $table->unsignedTinyInteger('depth')->default(0);
            $table->unsignedInteger('position')->default(0);

            $table->text('description_above')->nullable();
            $table->text('description_below')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_visible')->default(true);

            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('seo_image_path')->nullable();

            $table->timestamps();

            // Slugs are unique per shop, never globally: the first tenant to
            // register "knihy" must not block every other shop.
            $table->unique(['tenant_id', 'slug']);

            // Leads with tenant_id so the global scope can use it; covers the
            // ordinary "children of X, in order" read.
            $table->index(['tenant_id', 'parent_id', 'position']);
            $table->index(['tenant_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
