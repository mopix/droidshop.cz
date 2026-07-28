<?php

namespace Tests\Feature\Modules\Feeds;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Feeds\Models\ProductFeed;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Products\Services\VariantWriter;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class FeedEndpointTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();

        foreach (['storefront', 'products', 'categories', 'feeds'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function enable(string $type = ProductFeed::TYPE_HEUREKA): void
    {
        $this->context->runAs($this->tenant, fn () => ProductFeed::query()->create([
            'type' => $type,
            'enabled' => true,
            'settings' => ['delivery_date' => 5],
        ]));
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

    private function fetch(string $path = '/feed/heureka.xml'): string
    {
        $response = $this->get('http://shop1.droidshop'.$path);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        return $response->getContent();
    }

    public function test_an_enabled_feed_lists_the_catalogue(): void
    {
        $this->enable();
        $this->product();

        $xml = $this->fetch();

        $this->assertStringContainsString('<SHOP>', $xml);
        $this->assertStringContainsString('<PRODUCTNAME>Klávesnice Acme</PRODUCTNAME>', $xml);
        $this->assertStringContainsString('<PRICE_VAT>1290.00</PRICE_VAT>', $xml);
        $this->assertStringContainsString('/produkt/klavesnice-acme', $xml);
    }

    public function test_a_feed_that_was_never_enabled_is_a_404(): void
    {
        $this->product();

        $this->get('http://shop1.droidshop/feed/heureka.xml')->assertNotFound();
    }

    public function test_a_disabled_feed_is_a_404(): void
    {
        $this->context->runAs($this->tenant, fn () => ProductFeed::query()->create([
            'type' => ProductFeed::TYPE_HEUREKA,
            'enabled' => false,
        ]));

        $this->get('http://shop1.droidshop/feed/heureka.xml')->assertNotFound();
    }

    public function test_variants_share_an_item_group(): void
    {
        $this->enable();
        $product = $this->product();

        $this->context->runAs($this->tenant, function () use ($product) {
            $writer = app(VariantWriter::class);
            $writer->upsertVariant($product, ['Velikost' => 'M'], ['sku' => 'ACME-1-M', 'price' => 129000]);
            $writer->upsertVariant($product, ['Velikost' => 'L'], ['sku' => 'ACME-1-L', 'price' => 139000]);
        });

        $xml = $this->fetch();

        $this->assertSame(2, substr_count($xml, '<ITEMGROUP_ID>'.$product->id.'</ITEMGROUP_ID>'));
        $this->assertStringContainsString('<VAL>M</VAL>', $xml);
    }

    public function test_an_out_of_stock_item_stays_with_a_delivery_date(): void
    {
        $this->enable();
        $this->product(['stock_tracked' => true, 'stock_qty' => 0]);

        $xml = $this->fetch();

        $this->assertStringContainsString('<PRODUCTNAME>Klávesnice Acme</PRODUCTNAME>', $xml);
        $this->assertStringContainsString('<DELIVERY_DATE>5</DELIVERY_DATE>', $xml);
    }

    public function test_a_name_with_xml_characters_does_not_break_the_feed(): void
    {
        $this->enable();
        $this->product(['name' => 'Myš & klávesnice <sada>', 'slug' => 'sada']);

        $xml = $this->fetch();

        $this->assertStringContainsString('&amp;', $xml);
        $this->assertStringNotContainsString('<sada>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'The feed must be well-formed XML.');
    }

    public function test_the_feed_of_one_shop_never_carries_another_shops_products(): void
    {
        $this->enable();
        $this->product();

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'products');

        $this->context->runAs($other, fn () => app(ProductWriter::class)->create([
            'name' => 'Cizí produkt',
            'sku' => 'FOREIGN-1',
            'price' => 50000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));

        $xml = $this->fetch();

        $this->assertStringNotContainsString('Cizí produkt', $xml);
    }

    public function test_the_zbozi_feed_has_its_own_shape(): void
    {
        $this->enable(ProductFeed::TYPE_ZBOZI);
        $this->product();

        $xml = $this->fetch('/feed/zbozi.xml');

        $this->assertStringContainsString('<SHOP>', $xml);
        $this->assertStringContainsString('<PRICE_VAT>1290.00</PRICE_VAT>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_an_unknown_feed_type_is_a_404(): void
    {
        $this->get('http://shop1.droidshop/feed/google.xml')->assertNotFound();
    }

    /**
     * AK 9 — the DELIVERY block carries real shipping methods.
     */
    public function test_the_delivery_block_carries_real_shipping_methods(): void
    {
        $this->activateModule($this->tenant, 'shipping');
        $this->enable();
        $this->product();

        $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
            'is_active' => true,
        ]));

        $xml = $this->fetch();

        $this->assertStringContainsString('<DELIVERY_ID>Kurýr</DELIVERY_ID>', $xml);
        $this->assertStringContainsString('<DELIVERY_PRICE>99.00</DELIVERY_PRICE>', $xml);
    }

    /**
     * Without the shipping module the block is absent entirely, because a zero
     * price would promise free delivery the shop never offered.
     */
    public function test_without_the_shipping_module_there_is_no_delivery_block(): void
    {
        $this->enable();
        $this->product();

        $this->assertStringNotContainsString('<DELIVERY>', $this->fetch());
    }
}
