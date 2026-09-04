<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Money\Money;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * What a shopper sees on a discounted product, rendered server-side — the
 * statutory 30-day line included (§ 12a of the consumer protection act).
 *
 * Every assertion reads the raw HTML, so it also proves the page works with
 * JavaScript switched off (.claude/rules/storefront-rendering.md).
 */
class SaleStorefrontTest extends TestCase
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

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'products', 'categories'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    /**
     * Formatted the way the views format it — the thousands separator is a
     * non-breaking space, so a literal typed with an ordinary space would
     * never match the rendered page.
     */
    private function money(int $amount): string
    {
        return (new Money($amount, 'CZK'))->format();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(array $attributes = []): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create(array_merge([
            'name' => 'Klávesnice Acme',
            'slug' => 'klavesnice-acme',
            'price' => 100000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ], $attributes)));
    }

    public function test_a_discounted_product_shows_both_prices_and_the_statutory_line(): void
    {
        $this->makeProduct();

        // A day at the shelf price first, so the 30-day low has something
        // older than the campaign to report.
        Carbon::setTestNow(now()->addDay());

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->firstOrFail());
        $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->update($product, ['sale_price' => 79900]));

        $response = $this->get($this->url('/produkt/klavesnice-acme'));

        $response->assertOk();
        $response->assertSee($this->money(79900), false);
        $response->assertSee($this->money(100000), false);
        $response->assertSee('Nejnižší cena za posledních 30 dní', false);
        $response->assertSee('data-sale-badge', false);
    }

    public function test_the_saving_is_measured_against_the_same_reference_as_the_percentage(): void
    {
        // Two figures about one discount have to describe the same discount:
        // the percentage is computed from the 30-day low, so the koruna
        // saving is too. Measuring it against the shelf price instead would
        // put a bigger number next to a smaller percentage on the same line.
        $this->makeProduct();

        Carbon::setTestNow(now()->addDay());

        $product = $this->context->runAs($this->tenant, fn () => Product::query()->firstOrFail());
        $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->update($product, ['sale_price' => 79900]));

        $response = $this->get($this->url('/produkt/klavesnice-acme'));

        $response->assertOk();
        $response->assertSee('data-sale-saving', false);
        // 1000 − 799 = 201 Kč, the same 20 % the badge claims.
        $response->assertSee($this->money(20100), false);
    }

    public function test_a_product_launched_into_a_sale_shows_no_saving_either(): void
    {
        // No reference means no percentage, and a saving without a reference
        // is the same unfounded claim in korunas.
        $this->makeProduct(['sale_price' => 79900]);

        $response = $this->get($this->url('/produkt/klavesnice-acme'));

        $response->assertOk();
        $response->assertDontSee('data-sale-saving', false);
    }

    public function test_a_product_without_a_sale_shows_no_struck_price(): void
    {
        $this->makeProduct();

        $response = $this->get($this->url('/produkt/klavesnice-acme'));

        $response->assertOk();
        $response->assertDontSee('Nejnižší cena za posledních 30 dní', false);
        $response->assertDontSee('data-sale-badge', false);
    }

    public function test_a_product_launched_straight_into_a_sale_shows_the_line_without_a_percentage(): void
    {
        // No history older than the campaign, so the 30-day low equals the
        // sale price: the reference exists but the discount against it is
        // zero, and a badge claiming otherwise would be a lie.
        $this->makeProduct(['sale_price' => 79900]);

        $response = $this->get($this->url('/produkt/klavesnice-acme'));

        $response->assertOk();
        $response->assertSee('Nejnižší cena za posledních 30 dní', false);
        $response->assertDontSee('data-sale-badge', false);
    }

    public function test_a_category_listing_strikes_through_the_regular_price(): void
    {
        $this->makeProduct(['sale_price' => 79900]);

        $response = $this->get($this->url('/hledani?q=Klávesnice'));

        $response->assertOk();
        $response->assertSee($this->money(79900), false);
        $response->assertSee($this->money(100000), false);
    }
}
