<?php

namespace Tests\Feature\Storefront;

use App\Core\Modules\ModuleRegistry;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Models\Category;
use Modules\Categories\Services\CategoryTree;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The public catalogue: what a customer and a crawler actually receive.
 */
class StorefrontCatalogTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenantA = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);
        $this->tenantB = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);

        foreach (['categories', 'products', 'storefront'] as $module) {
            $this->activateModule($this->tenantA, $module);
            $this->activateModule($this->tenantB, $module);
        }
    }

    private function inShop(Tenant $tenant, callable $callback): mixed
    {
        return $this->context->runAs($tenant, $callback);
    }

    private function makeProduct(Tenant $tenant, array $attributes = [], ?Category $category = null): Product
    {
        return $this->inShop($tenant, function () use ($attributes, $category) {
            $product = app(ProductWriter::class)->create([
                'name' => 'Notebook Acme 14',
                'price' => 24_990_00,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                ...$attributes,
            ]);

            if ($category !== null) {
                app(ProductWriter::class)->syncCategories($product, [$category->id], $category->id);
            }

            return $product;
        });
    }

    private function makeCategory(Tenant $tenant, array $attributes = [], ?Category $parent = null): Category
    {
        return $this->inShop($tenant, fn () => app(CategoryTree::class)->create([
            'name' => 'Notebooky',
            'is_visible' => true,
            ...$attributes,
        ], $parent));
    }

    public function test_homepage_renders_the_shop_not_the_platform_page(): void
    {
        $this->makeProduct($this->tenantA);

        $this->get('http://shop1.droidshop/')
            ->assertOk()
            ->assertSee('Shop One')
            ->assertSee('Notebook Acme 14');
    }

    public function test_platform_host_keeps_its_own_homepage(): void
    {
        $this->get('http://droidshop/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Welcome'));
    }

    public function test_homepage_does_not_leak_another_shops_products(): void
    {
        $this->makeProduct($this->tenantA, ['name' => 'Tajny produkt A']);

        $this->get('http://shop2.droidshop/')->assertOk()->assertDontSee('Tajny produkt A');
    }

    public function test_product_detail_is_server_rendered_with_price_in_the_html(): void
    {
        $this->makeProduct($this->tenantA, ['slug' => 'notebook-acme-14']);

        $response = $this->get('http://shop1.droidshop/produkt/notebook-acme-14')->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('Notebook Acme 14', $html);
        // The price has to be in the first response, not fetched afterwards.
        $this->assertMatchesRegularExpression('/24\s?990/u', $html);
    }

    public function test_draft_and_hidden_products_are_not_public(): void
    {
        $this->makeProduct($this->tenantA, ['slug' => 'draft-produkt', 'status' => Product::STATUS_DRAFT]);
        $this->makeProduct($this->tenantA, ['slug' => 'skryty-produkt', 'status' => Product::STATUS_HIDDEN]);

        $this->get('http://shop1.droidshop/produkt/draft-produkt')->assertNotFound();
        $this->get('http://shop1.droidshop/produkt/skryty-produkt')->assertNotFound();
    }

    public function test_product_of_another_tenant_is_not_reachable(): void
    {
        $this->makeProduct($this->tenantA, ['slug' => 'notebook-acme-14']);

        $this->get('http://shop2.droidshop/produkt/notebook-acme-14')->assertNotFound();
    }

    public function test_product_detail_carries_the_required_seo_output(): void
    {
        $this->makeProduct($this->tenantA, [
            'slug' => 'notebook-acme-14',
            'seo_title' => 'Notebook Acme 14 | Shop One',
            'seo_description' => 'Lehký notebook.',
        ]);

        $response = $this->get('http://shop1.droidshop/produkt/notebook-acme-14')->assertOk();

        $response->assertSee('<title>Notebook Acme 14 | Shop One</title>', false);
        $response->assertSee('<meta name="description" content="Lehký notebook.">', false);
        $response->assertSee('<link rel="canonical" href="http://shop1.droidshop/produkt/notebook-acme-14">', false);
        $response->assertSee('og:type" content="product"', false);

        $this->assertNotNull($this->productJsonLd($response->getContent()));
    }

    public function test_product_json_ld_offer_is_valid(): void
    {
        $this->makeProduct($this->tenantA, ['slug' => 'notebook-acme-14', 'sku' => 'ACME-14']);

        $data = $this->productJsonLd(
            $this->get('http://shop1.droidshop/produkt/notebook-acme-14')->getContent()
        );

        $this->assertSame('Product', $data['@type']);
        $this->assertSame('ACME-14', $data['sku']);
        $this->assertSame('24990.00', $data['offers']['price']);
        $this->assertSame('CZK', $data['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $data['offers']['availability']);
    }

    public function test_category_lists_products_of_its_whole_subtree(): void
    {
        $parent = $this->makeCategory($this->tenantA, ['name' => 'Elektronika', 'slug' => 'elektronika']);
        $child = $this->makeCategory($this->tenantA, ['name' => 'Notebooky', 'slug' => 'notebooky'], $parent);

        $this->makeProduct($this->tenantA, ['name' => 'Produkt v podkategorii', 'slug' => 'p1'], $child);

        $this->get('http://shop1.droidshop/kategorie/elektronika')
            ->assertOk()
            ->assertSee('Produkt v podkategorii');
    }

    public function test_hidden_category_is_not_public(): void
    {
        $this->makeCategory($this->tenantA, ['slug' => 'skryta', 'is_visible' => false]);

        $this->get('http://shop1.droidshop/kategorie/skryta')->assertNotFound();
    }

    public function test_empty_category_answers_instead_of_404(): void
    {
        $this->makeCategory($this->tenantA, ['slug' => 'prazdna']);

        $this->get('http://shop1.droidshop/kategorie/prazdna')
            ->assertOk()
            ->assertSee('zatím nic nenabízíme');
    }

    public function test_category_of_another_tenant_is_not_reachable(): void
    {
        $this->makeCategory($this->tenantA, ['slug' => 'notebooky']);

        $this->get('http://shop2.droidshop/kategorie/notebooky')->assertNotFound();
    }

    public function test_sorting_works_from_the_query_string_alone(): void
    {
        $category = $this->makeCategory($this->tenantA, ['slug' => 'vse']);

        $this->makeProduct($this->tenantA, ['name' => 'Levny', 'slug' => 'levny', 'price' => 100_00], $category);
        $this->makeProduct($this->tenantA, ['name' => 'Drahy', 'slug' => 'drahy', 'price' => 900_00], $category);

        $html = $this->get('http://shop1.droidshop/kategorie/vse?razeni=cena-asc')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Drahy'), strpos($html, 'Levny'));
    }

    public function test_filtered_listing_is_noindex(): void
    {
        $this->makeCategory($this->tenantA, ['slug' => 'vse']);

        $this->get('http://shop1.droidshop/kategorie/vse')
            ->assertSee('content="index, follow"', false);

        $this->get('http://shop1.droidshop/kategorie/vse?skladem=1')
            ->assertSee('content="noindex, follow"', false);
    }

    public function test_storefront_routes_do_not_exist_on_the_platform_host(): void
    {
        $this->makeProduct($this->tenantA, ['slug' => 'notebook-acme-14']);

        $this->get('http://droidshop/produkt/notebook-acme-14')->assertNotFound();
    }

    public function test_a_shop_without_the_products_module_has_no_product_pages(): void
    {
        $this->makeProduct($this->tenantB, ['slug' => 'produkt-b']);

        app(ModuleRegistry::class)->deactivate($this->tenantB, 'products');

        // 404, not a redirect to a login: the shop must not disclose which
        // modules it runs.
        $this->get('http://shop2.droidshop/produkt/produkt-b')->assertNotFound();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function productJsonLd(string $html): ?array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        foreach ($matches[1] as $json) {
            $data = json_decode($json, true);

            if (($data['@type'] ?? null) === 'Product') {
                return $data;
            }
        }

        return null;
    }
}
