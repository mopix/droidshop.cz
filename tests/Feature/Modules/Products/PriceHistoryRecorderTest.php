<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductPriceHistory;
use Modules\Products\Services\ProductWriter;
use Tests\TestCase;

class PriceHistoryRecorderTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();

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
    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create(array_merge([
            'name' => 'Klávesnice Acme',
            'price' => 100000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ], $attributes)));
    }

    /**
     * @return Collection<int, ProductPriceHistory>
     */
    private function rows(Tenant $tenant, Product $product): Collection
    {
        return $this->context->runAs($tenant, fn () => ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->orderBy('starts_at')
            ->get());
    }

    public function test_creating_a_product_opens_one_interval(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $rows = $this->rows($tenant, $product);

        $this->assertCount(1, $rows);
        $this->assertSame(100000, $rows[0]->price->amount);
        $this->assertNull($rows[0]->ends_at);
    }

    public function test_a_price_change_closes_the_old_interval_and_opens_a_new_one(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        Carbon::setTestNow(Carbon::parse('2026-07-28 15:00:00'));

        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, ['price' => 90000]));

        $rows = $this->rows($tenant, $product);

        $this->assertCount(2, $rows);
        $this->assertSame(100000, $rows[0]->price->amount);
        $this->assertSame('2026-07-28 15:00:00', $rows[0]->ends_at->toDateTimeString());
        $this->assertSame(90000, $rows[1]->price->amount);
        $this->assertNull($rows[1]->ends_at);
    }

    public function test_a_scheduled_sale_is_written_ahead_of_time(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, [
            'sale_price' => 79900,
            'sale_starts_at' => '2026-08-01 00:00:00',
            'sale_ends_at' => '2026-08-08 00:00:00',
        ]));

        $rows = $this->rows($tenant, $product);

        // regular now → sale window → back to regular, all three already in
        // the table so the end of the campaign needs no scheduler.
        $this->assertCount(3, $rows);
        $this->assertSame(100000, $rows[0]->price->amount);
        $this->assertSame('2026-08-01 00:00:00', $rows[0]->ends_at->toDateTimeString());
        $this->assertSame(79900, $rows[1]->price->amount);
        $this->assertSame('2026-08-08 00:00:00', $rows[1]->ends_at->toDateTimeString());
        $this->assertSame(100000, $rows[2]->price->amount);
        $this->assertNull($rows[2]->ends_at);
    }

    public function test_an_edit_never_rewrites_an_interval_that_already_started(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $firstId = $this->rows($tenant, $product)[0]->id;

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:00:00'));

        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, ['price' => 90000]));

        $past = $this->rows($tenant, $product)->firstWhere('id', $firstId);

        $this->assertNotNull($past);
        $this->assertSame(100000, $past->price->amount);
        $this->assertSame('2026-07-28 12:00:00', $past->starts_at->toDateTimeString());
    }

    public function test_rescheduling_a_sale_replaces_only_the_future_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $product = $this->makeProduct($tenant);

        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product, [
            'sale_price' => 79900,
            'sale_starts_at' => '2026-08-01 00:00:00',
            'sale_ends_at' => '2026-08-08 00:00:00',
        ]));

        $this->context->runAs($tenant, fn () => app(ProductWriter::class)->update($product->fresh(), [
            'sale_price' => 69900,
            'sale_starts_at' => '2026-08-02 00:00:00',
            'sale_ends_at' => '2026-08-03 00:00:00',
        ]));

        $rows = $this->rows($tenant, $product);

        $this->assertCount(3, $rows);
        $this->assertSame(69900, $rows[1]->price->amount);
        $this->assertSame('2026-08-02 00:00:00', $rows[1]->starts_at->toDateTimeString());
    }

    public function test_history_is_scoped_to_its_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $product = $this->makeProduct($a);

        $visible = $this->context->runAs($b, fn () => ProductPriceHistory::query()->count());

        $this->assertSame(0, $visible);
        $this->assertCount(1, $this->rows($a, $product));
    }
}
