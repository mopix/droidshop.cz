<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Page-cache generation counters (wave 3.0).
     *
     * These live on the tenant row rather than in the cache store on purpose.
     * A counter kept in cache can be evicted; it would come back as 1 and any
     * page still stored under the original generation 1 would be served again
     * — content the tenant changed long ago, resurrected. The tenant row is
     * loaded on every request anyway (DomainTenantFinder), so three integer
     * columns cost no extra query.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('page_gen_catalog')->default(1);
            $table->unsignedBigInteger('page_gen_content')->default(1);
            $table->unsignedBigInteger('page_gen_theme')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['page_gen_catalog', 'page_gen_content', 'page_gen_theme']);
        });
    }
};
