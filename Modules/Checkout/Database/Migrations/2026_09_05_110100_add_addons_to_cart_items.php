<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            // An addon is its own line, not a modifier folded into the product's
            // price. It has to reach the invoice as a line of its own with its
            // own VAT rate, and a cart that models it differently from the
            // order is a cart whose total nobody can reconcile.
            //
            // 0 rather than NULL, for the same reason variant_id is: MySQL
            // treats every NULL in a unique index as distinct, so a nullable
            // column would let the same line be inserted twice.
            $table->unsignedBigInteger('addon_id')->default(0)->after('variant_id');
            $table->unsignedBigInteger('parent_item_id')->nullable()->after('addon_id');

            // The chosen accessories, folded into one value so the unique index
            // can tell two otherwise identical lines apart: the same picture
            // with an oak frame and with no frame are two things a customer
            // bought. Comparing addon_id alone cannot do it — both product
            // lines carry 0 there.
            $table->char('addon_hash', 32)->default('')->after('parent_item_id');

            // Rebuilt to include the addon: the same product with two different
            // frames is two lines, and without this the second insert merges
            // into the first.
            $table->dropUnique('cart_item_unique');
            $table->unique(['tenant_id', 'cart_id', 'product_id', 'variant_id', 'addon_id', 'addon_hash'], 'cart_item_unique');

            $table->index(['tenant_id', 'parent_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('cart_item_unique');
            $table->dropIndex(['tenant_id', 'parent_item_id']);
            $table->dropColumn(['addon_id', 'parent_item_id', 'addon_hash']);
            $table->unique(['tenant_id', 'cart_id', 'product_id', 'variant_id'], 'cart_item_unique');
        });
    }
};
