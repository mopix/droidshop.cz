<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing products get one open interval starting now. Older history
        // does not exist and inventing it would falsify the very document the
        // table is meant to be — a shop's first 30 days after this migration
        // simply report the price it is running today.
        $now = now();

        DB::table('products')->orderBy('id')->chunkById(200, function ($products) use ($now) {
            $rows = [];

            foreach ($products as $product) {
                $rows[] = [
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'price' => $product->price,
                    'currency' => $product->currency,
                    'starts_at' => $now,
                    'ends_at' => null,
                    'created_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('product_price_history')->insert($rows);
            }
        });

        DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->orderBy('product_variants.id')
            ->select([
                'product_variants.id',
                'product_variants.tenant_id',
                'product_variants.product_id',
                'product_variants.price as variant_price',
                'products.price as product_price',
                'products.currency',
            ])
            ->chunkById(200, function ($variants) use ($now) {
                $rows = [];

                foreach ($variants as $variant) {
                    $rows[] = [
                        'tenant_id' => $variant->tenant_id,
                        'product_id' => $variant->product_id,
                        'variant_id' => $variant->id,
                        // Null means the variant inherits the product's price,
                        // and the inherited figure is what it was sold at.
                        'price' => $variant->variant_price ?? $variant->product_price,
                        'currency' => $variant->currency,
                        'starts_at' => $now,
                        'ends_at' => null,
                        'created_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('product_price_history')->insert($rows);
                }
            }, 'product_variants.id', 'id');
    }

    public function down(): void
    {
        DB::table('product_price_history')->delete();
    }
};
