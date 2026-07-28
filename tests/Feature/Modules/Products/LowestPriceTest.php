<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Products\Models\Product;
use Modules\Products\Services\LowestPriceCalculator;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

/**
 * § 12a of the consumer protection act: next to an announced discount a shop
 * must state the lowest price it actually sold at over the preceding 30 days.
 */
class LowestPriceTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create(array_merge([
            'name' => 'Klávesnice Acme',
            'price' => 100000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ], $attributes)));
    }

    public function test_a_product_never_discounted_reports_its_own_price(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertSame(100000, app(LowestPriceCalculator::class)->forProduct($product)->amount);
        });
    }

    public function test_it_reports_the_lowest_price_inside_the_window(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));
        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, ['price' => 80000]));

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));
        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product->fresh(), ['price' => 95000]));

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertSame(80000, app(LowestPriceCalculator::class)->forProduct($product->fresh())->amount);
        });
    }

    public function test_a_price_that_ended_before_the_window_is_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant, ['price' => 50000]);

        // The cheap period ends on 2 June; by 10 July it is more than 30 days
        // behind us and must not be reported as the recent low.
        Carbon::setTestNow(Carbon::parse('2026-06-02 12:00:00'));
        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, ['price' => 100000]));

        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00'));

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertSame(100000, app(LowestPriceCalculator::class)->forProduct($product->fresh())->amount);
        });
    }

    public function test_an_interval_that_started_before_the_window_and_still_runs_counts(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant, ['price' => 60000]);

        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00'));

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertSame(60000, app(LowestPriceCalculator::class)->forProduct($product->fresh())->amount);
        });
    }

    /**
     * A campaign is never part of its own reference: the statutory figure is
     * the lowest price of the 30 days BEFORE the discount was granted. Were
     * the sale price counted in, every reference would equal the sale price
     * and every announced discount would be 0 %.
     */
    public function test_a_running_sale_is_not_part_of_its_own_reference(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        Carbon::setTestNow(Carbon::parse('2026-06-05 12:00:00'));
        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, ['sale_price' => 70000]));

        $this->context->runAs($tenant, function () use ($product) {
            $fresh = $product->fresh();

            $this->assertTrue($fresh->catalogIsOnSale());
            $this->assertSame(100000, app(LowestPriceCalculator::class)->forProduct($fresh)->amount);
            $this->assertSame(100000, $fresh->catalogLowestPriceIn30Days()->amount);
        });
    }

    /**
     * An earlier campaign inside the window does count — that is the whole
     * point of the rule: a shop cannot inflate the shelf price for a day and
     * announce a discount from it.
     */
    public function test_an_earlier_campaign_inside_the_window_sets_the_reference(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        Carbon::setTestNow(Carbon::parse('2026-06-05 12:00:00'));
        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, [
            'sale_price' => 75000,
            'sale_ends_at' => '2026-06-08 12:00:00',
        ]));

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));
        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product->fresh(), [
            'sale_price' => 70000,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
        ]));

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertSame(75000, app(LowestPriceCalculator::class)->forProduct($product->fresh())->amount);
        });
    }

    /**
     * A product put on sale the moment it was created has nothing older than
     * its own campaign, so the reference falls back to the price it sells at.
     */
    public function test_a_product_launched_straight_into_a_sale_reports_its_sale_price(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant, ['sale_price' => 70000]);

        $this->context->runAs($tenant, function () use ($product) {
            $this->assertSame(70000, app(LowestPriceCalculator::class)->forProduct($product->fresh())->amount);
        });
    }
}
