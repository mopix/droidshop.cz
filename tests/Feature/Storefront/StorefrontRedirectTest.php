<?php

namespace Tests\Feature\Storefront;

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
 * Renamed and withdrawn things (spec §15.3, §16.1).
 *
 * Wave 1.1 recorded redirects but nothing served them, so a renamed slug was a
 * 404 and the link equity was lost. These tests are the reason that changed.
 */
class StorefrontRedirectTest extends TestCase
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

        foreach ([$this->tenantA, $this->tenantB] as $tenant) {
            foreach (['categories', 'products', 'storefront'] as $module) {
                $this->activateModule($tenant, $module);
            }
        }
    }

    private function inShop(Tenant $tenant, callable $callback): mixed
    {
        return $this->context->runAs($tenant, $callback);
    }

    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->inShop($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Notebook Acme 14',
            'price' => 24_990_00,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            ...$attributes,
        ]));
    }

    public function test_renamed_product_slug_answers_with_a_301(): void
    {
        $product = $this->makeProduct($this->tenantA, ['slug' => 'stary-slug']);

        $this->inShop($this->tenantA, fn () => app(ProductWriter::class)->update($product, ['slug' => 'novy-slug']));

        $this->get('http://shop1.droidshop/produkt/stary-slug')
            ->assertStatus(301)
            ->assertRedirect('http://shop1.droidshop/produkt/novy-slug');
    }

    public function test_renamed_category_slug_answers_with_a_301(): void
    {
        $category = $this->inShop($this->tenantA, fn () => app(CategoryTree::class)->create([
            'name' => 'Notebooky',
            'slug' => 'notebooky',
            'is_visible' => true,
        ]));

        $this->inShop($this->tenantA, fn () => app(CategoryTree::class)->update($category, ['slug' => 'pocitace']));

        $this->get('http://shop1.droidshop/kategorie/notebooky')
            ->assertStatus(301)
            ->assertRedirect('http://shop1.droidshop/kategorie/pocitace');
    }

    public function test_a_chain_of_renames_is_a_single_hop(): void
    {
        $product = $this->makeProduct($this->tenantA, ['slug' => 'prvni']);

        $this->inShop($this->tenantA, function () use ($product): void {
            app(ProductWriter::class)->update($product, ['slug' => 'druhy']);
            app(ProductWriter::class)->update($product->fresh(), ['slug' => 'treti']);
        });

        $this->get('http://shop1.droidshop/produkt/prvni')
            ->assertStatus(301)
            ->assertRedirect('http://shop1.droidshop/produkt/treti');
    }

    public function test_query_string_survives_the_redirect(): void
    {
        $product = $this->makeProduct($this->tenantA, ['slug' => 'stary']);

        $this->inShop($this->tenantA, fn () => app(ProductWriter::class)->update($product, ['slug' => 'novy']));

        $this->get('http://shop1.droidshop/produkt/stary?utm_source=heureka')
            ->assertRedirect('http://shop1.droidshop/produkt/novy?utm_source=heureka');
    }

    public function test_a_redirect_does_not_cross_tenants(): void
    {
        $product = $this->makeProduct($this->tenantA, ['slug' => 'stary-slug']);

        $this->inShop($this->tenantA, fn () => app(ProductWriter::class)->update($product, ['slug' => 'novy-slug']));

        $this->get('http://shop2.droidshop/produkt/stary-slug')->assertNotFound();
    }

    public function test_withdrawn_product_answers_410_with_a_way_back(): void
    {
        $category = $this->inShop($this->tenantA, fn () => app(CategoryTree::class)->create([
            'name' => 'Notebooky', 'slug' => 'notebooky', 'is_visible' => true,
        ]));

        $product = $this->makeProduct($this->tenantA, ['slug' => 'stazeny']);

        $this->inShop($this->tenantA, function () use ($product, $category): void {
            app(ProductWriter::class)->syncCategories($product, [$category->id], $category->id);
            app(ProductWriter::class)->delete($product->fresh());
        });

        $this->get('http://shop1.droidshop/produkt/stazeny')
            ->assertStatus(410)
            ->assertSee('už není v nabídce')
            ->assertSee('/kategorie/notebooky', false)
            ->assertSee('content="noindex, follow"', false);
    }

    public function test_unknown_path_renders_the_shops_own_404(): void
    {
        $this->get('http://shop1.droidshop/neexistuje')
            ->assertNotFound()
            ->assertSee('Shop One')
            ->assertSee('Stránka nenalezena');
    }

    public function test_a_post_to_a_renamed_path_is_not_replayed(): void
    {
        $product = $this->makeProduct($this->tenantA, ['slug' => 'stary']);

        $this->inShop($this->tenantA, fn () => app(ProductWriter::class)->update($product, ['slug' => 'novy']));

        // 405, not 301: redirecting a write would send the body to an address
        // the caller never chose.
        $this->post('http://shop1.droidshop/produkt/stary')->assertStatus(405);
    }

    public function test_category_helper_still_records_the_redirect_for_a_hidden_category(): void
    {
        $category = $this->inShop($this->tenantA, fn () => app(CategoryTree::class)->create([
            'name' => 'Skrytá', 'slug' => 'skryta', 'is_visible' => false,
        ]));

        $this->inShop($this->tenantA, fn () => app(CategoryTree::class)->update($category, ['slug' => 'skryta-nova']));

        // The redirect resolves, and the target is still not public — a hidden
        // category must not become reachable through its own rename.
        $this->get('http://shop1.droidshop/kategorie/skryta')->assertStatus(301);
        $this->get('http://shop1.droidshop/kategorie/skryta-nova')->assertNotFound();
    }

    public function test_unknown_category_is_untouched_by_redirects(): void
    {
        $this->assertNull($this->inShop($this->tenantA, fn () => Category::query()->first()));

        $this->get('http://shop1.droidshop/kategorie/nikdy-neexistovala')->assertNotFound();
    }
}
