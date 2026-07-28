<?php

namespace Tests\Feature\Modules\Feeds;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Feeds\Models\ProductFeed;
use Modules\Feeds\Support\FeedItem;
use Modules\Feeds\Support\FeedItemBuilder;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Products\Services\VariantWriter;
use Tests\TestCase;

class FeedItemBuilderTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->context->forget();
        $this->tenant = Tenant::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'sku' => 'ACME-1',
            'price' => 129000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            ...$attributes,
        ]));
    }

    /**
     * @return list<FeedItem>
     */
    private function items(int $deliveryDays = 7): array
    {
        return $this->context->runAs($this->tenant, fn () => iterator_to_array(
            app(FeedItemBuilder::class)->items(ProductFeed::TYPE_HEUREKA, $deliveryDays),
            false,
        ));
    }

    public function test_an_active_product_becomes_one_item(): void
    {
        $this->product();

        $items = $this->items();

        $this->assertCount(1, $items);
        $this->assertSame('Klávesnice Acme', $items[0]->name);
        $this->assertSame(129000, $items[0]->priceVat->amount);
        $this->assertNull($items[0]->itemGroupId);
        $this->assertSame('ACME-1', $items[0]->sku);
    }

    public function test_a_draft_and_a_hidden_product_stay_out(): void
    {
        $this->product(['status' => Product::STATUS_DRAFT, 'sku' => 'D-1', 'slug' => 'draft']);
        $this->product(['status' => Product::STATUS_HIDDEN, 'sku' => 'H-1', 'slug' => 'hidden']);

        $this->assertSame([], $this->items());
    }

    public function test_a_running_sale_sets_the_price(): void
    {
        $this->product(['sale_price' => 99000]);

        $this->assertSame(99000, $this->items()[0]->priceVat->amount);
    }

    public function test_each_variant_is_its_own_item_under_a_shared_group(): void
    {
        $product = $this->product();

        $this->context->runAs($this->tenant, function () use ($product) {
            $writer = app(VariantWriter::class);
            $writer->upsertVariant($product, ['Velikost' => 'M'], ['sku' => 'ACME-1-M', 'price' => 129000]);
            $writer->upsertVariant($product, ['Velikost' => 'L'], ['sku' => 'ACME-1-L', 'price' => 139000]);
        });

        $items = $this->items();

        $this->assertCount(2, $items);
        $this->assertSame([(string) $product->id, (string) $product->id], [
            $items[0]->itemGroupId, $items[1]->itemGroupId,
        ]);
        $this->assertSame(['Velikost' => 'M'], $items[0]->params);
        $this->assertSame(139000, $items[1]->priceVat->amount);
    }

    public function test_stock_decides_the_delivery_date(): void
    {
        $this->product(['stock_tracked' => true, 'stock_qty' => 0]);

        $this->assertSame(7, $this->items(deliveryDays: 7)[0]->deliveryDays);
    }

    public function test_something_in_stock_ships_today(): void
    {
        $this->product(['stock_tracked' => true, 'stock_qty' => 5]);

        $this->assertSame(0, $this->items()[0]->deliveryDays);
    }

    public function test_the_description_carries_no_markup(): void
    {
        $this->product(['description' => '<p>Skvělá <strong>klávesnice</strong></p>']);

        $description = $this->items()[0]->description;

        $this->assertStringNotContainsString('<', $description);
        $this->assertStringContainsString('klávesnice', $description);
    }

    /**
     * Stripping tags without putting a space back glues paragraphs together —
     * "hliníkové tělo.Demo produkt" — and the comparison shopper prints that
     * to a customer verbatim.
     */
    public function test_paragraphs_do_not_get_glued_together(): void
    {
        $this->product(['description' => '<p>Hliníkové tělo.</p><p>Demo produkt.</p>']);

        $this->assertSame('Hliníkové tělo. Demo produkt.', $this->items()[0]->description);
    }
}
