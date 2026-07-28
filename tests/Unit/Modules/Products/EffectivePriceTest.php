<?php

namespace Tests\Unit\Modules\Products;

use App\Core\Money\Money;
use Illuminate\Support\Carbon;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

/**
 * The sale window arithmetic, with no database behind it: this is pure
 * decision logic and must be provable without a tenant, a migration or a
 * clock that moves on its own.
 *
 * Tests\TestCase rather than a bare PHPUnit one only because MoneyCast reads
 * the shop currency from config — nothing here touches the database.
 */
class EffectivePriceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-28 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        $product = new Product;
        $product->forceFill(array_merge([
            'price' => 100000,
            'currency' => 'CZK',
        ], $attributes));

        return $product;
    }

    public function test_a_product_without_a_sale_sells_at_its_regular_price(): void
    {
        $product = $this->product();

        $this->assertFalse($product->saleIsRunning());
        $this->assertSame(100000, $product->effectivePrice()->amount);
    }

    public function test_an_open_ended_sale_runs_from_the_moment_it_is_set(): void
    {
        $product = $this->product(['sale_price' => 79900]);

        $this->assertTrue($product->saleIsRunning());
        $this->assertSame(79900, $product->effectivePrice()->amount);
    }

    public function test_a_sale_scheduled_for_later_is_not_running_yet(): void
    {
        $product = $this->product([
            'sale_price' => 79900,
            'sale_starts_at' => '2026-07-29 00:00:00',
        ]);

        $this->assertFalse($product->saleIsRunning());
        $this->assertSame(100000, $product->effectivePrice()->amount);
    }

    public function test_a_sale_that_has_ended_is_no_longer_running(): void
    {
        $product = $this->product([
            'sale_price' => 79900,
            'sale_starts_at' => '2026-07-01 00:00:00',
            'sale_ends_at' => '2026-07-28 11:59:00',
        ]);

        $this->assertFalse($product->saleIsRunning());
        $this->assertSame(100000, $product->effectivePrice()->amount);
    }

    public function test_a_variant_without_its_own_price_inherits_the_products_sale(): void
    {
        $product = $this->product(['sale_price' => 79900]);

        $variant = new ProductVariant;
        $variant->forceFill(['price' => null, 'sale_price' => null]);
        $variant->setRelation('product', $product);

        $this->assertTrue($variant->saleIsRunning());
        $this->assertSame(79900, $variant->effectivePrice()->amount);
        $this->assertSame(100000, $variant->regularPrice()->amount);
    }

    public function test_a_variant_with_its_own_price_does_not_inherit_the_sale_amount(): void
    {
        $product = $this->product(['sale_price' => 79900]);

        $variant = new ProductVariant;
        $variant->forceFill(['price' => 120000, 'sale_price' => null]);
        $variant->setRelation('product', $product);

        $this->assertFalse($variant->saleIsRunning());
        $this->assertSame(120000, $variant->effectivePrice()->amount);
    }

    public function test_a_variant_may_be_on_sale_while_the_product_itself_is_not(): void
    {
        $product = $this->product(['sale_price' => null]);

        $variant = new ProductVariant;
        $variant->forceFill(['price' => 120000, 'sale_price' => 99900]);
        $variant->setRelation('product', $product);

        $this->assertTrue($variant->saleIsRunning());
        $this->assertSame(99900, $variant->effectivePrice()->amount);
        $this->assertSame(120000, $variant->regularPrice()->amount);
    }

    public function test_a_variant_sale_respects_the_products_window(): void
    {
        $product = $this->product([
            'sale_price' => null,
            'sale_starts_at' => '2026-07-29 00:00:00',
        ]);

        $variant = new ProductVariant;
        $variant->forceFill(['price' => 120000, 'sale_price' => 99900]);
        $variant->setRelation('product', $product);

        $this->assertFalse($variant->saleIsRunning());
        $this->assertSame(120000, $variant->effectivePrice()->amount);
    }

    public function test_the_effective_price_keeps_the_products_currency(): void
    {
        $product = $this->product(['sale_price' => 79900]);

        $this->assertInstanceOf(Money::class, $product->effectivePrice());
        $this->assertSame('CZK', $product->effectivePrice()->currency);
    }
}
