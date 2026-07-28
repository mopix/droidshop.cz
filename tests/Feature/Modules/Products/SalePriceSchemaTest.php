<?php

namespace Tests\Feature\Modules\Products;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalePriceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_carry_a_sale_price_and_its_window(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'sale_price'));
        $this->assertTrue(Schema::hasColumn('products', 'sale_starts_at'));
        $this->assertTrue(Schema::hasColumn('products', 'sale_ends_at'));
    }

    public function test_the_dead_compare_at_price_column_is_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('products', 'compare_at_price'));
    }

    public function test_variants_carry_their_own_sale_price(): void
    {
        $this->assertTrue(Schema::hasColumn('product_variants', 'sale_price'));
    }

    public function test_the_price_history_table_exists_with_a_tenant_scope(): void
    {
        $this->assertTrue(Schema::hasTable('product_price_history'));

        foreach (['tenant_id', 'product_id', 'variant_id', 'price', 'starts_at', 'ends_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('product_price_history', $column),
                "product_price_history is missing {$column}",
            );
        }
    }
}
