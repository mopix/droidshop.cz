<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Which product line this accessory belongs to, and which addon it
            // was. Both are snapshots of a relationship, not foreign keys into
            // the catalogue: an order is a record of what was sold, and it must
            // survive the merchant deleting the addon afterwards — the same
            // stance product_id already takes.
            $table->unsignedBigInteger('parent_item_id')->nullable()->after('variant_id');
            $table->unsignedBigInteger('addon_id')->nullable()->after('parent_item_id');

            $table->index(['tenant_id', 'parent_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'parent_item_id']);
            $table->dropColumn(['parent_item_id', 'addon_id']);
        });
    }
};
