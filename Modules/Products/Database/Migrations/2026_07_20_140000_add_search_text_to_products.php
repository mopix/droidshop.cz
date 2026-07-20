<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The folded form the storefront search compares against (spec §4.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('search_text')->nullable()->after('short_description');
        });

        // Existing rows are backfilled by `products:reindex-search`, not here:
        // folding runs in PHP, and a migration that loads every product of
        // every tenant would not survive the first large shop.
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('search_text');
        });
    }
};
