<?php

namespace Tests\Feature\Modules;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Limits\LimitOutcome;
use App\Core\Limits\LimitsService;
use App\Core\Modules\ModuleRegistry;
use App\Core\Money\Money;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Models\Category;
use Modules\Categories\Services\CategoryTree;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private ProductWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->writer = app(ProductWriter::class);
        $this->tenant = Tenant::factory()->create();

        $this->activateModule($this->tenant, 'products');
    }

    private function inShop(callable $callback): mixed
    {
        return $this->context->runAs($this->tenant, $callback);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function make(array $attributes = []): Product
    {
        return $this->writer->create([
            'name' => 'Notebook Acme 14',
            'price' => 24_990_00,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            ...$attributes,
        ]);
    }

    public function test_activating_products_pulls_in_categories(): void
    {
        // products requires categories; a shop cannot end up with a catalogue
        // that has nowhere to put anything.
        $this->assertTrue(
            app(ModuleRegistry::class)->isEnabled($this->tenant, 'categories')
        );
    }

    public function test_a_product_is_stored_with_a_generated_slug(): void
    {
        $this->inShop(function () {
            $product = $this->make();

            $this->assertSame('notebook-acme-14', $product->slug);
            $this->assertSame(Product::STATUS_DRAFT, $product->status);
        });
    }

    public function test_a_slug_collision_gets_a_suffix(): void
    {
        $this->inShop(function () {
            $this->make();
            $second = $this->make();

            $this->assertSame('notebook-acme-14-2', $second->slug);
        });
    }

    public function test_the_price_is_stored_as_haler_and_read_back_as_money(): void
    {
        $this->inShop(function () {
            $product = $this->make(['price' => 1_234_56]);

            $this->assertInstanceOf(Money::class, $product->fresh()->price);
            $this->assertSame(123456, $product->fresh()->price->amount);
        });
    }

    public function test_the_net_price_is_derived_from_the_rate_not_stored(): void
    {
        $this->inShop(function () {
            $product = $this->make(['price' => 121_00]);

            $this->assertSame(100_00, $product->netPrice()->amount);
            $this->assertSame(21_00, $product->vat()->amount);
        });
    }

    public function test_the_description_is_sanitised_on_write(): void
    {
        // Stored clean, not escaped at render: the storefront renders this as
        // HTML, and a tenant must not be able to script their own shop.
        $this->inShop(function () {
            $product = $this->make([
                'description' => '<p>Dobrý <strong>notebook</strong></p><script>alert(1)</script>',
            ]);

            $this->assertStringNotContainsString('<script', $product->description);
            $this->assertStringContainsString('<strong>notebook</strong>', $product->description);
        });
    }

    public function test_event_handlers_are_stripped_from_the_description(): void
    {
        $this->inShop(function () {
            $product = $this->make(['description' => '<p onclick="steal()">Text</p>']);

            $this->assertStringNotContainsString('onclick', $product->description);
        });
    }

    public function test_javascript_urls_are_stripped_from_links(): void
    {
        $this->inShop(function () {
            $product = $this->make(['description' => '<a href="javascript:alert(1)">klik</a>']);

            $this->assertStringNotContainsString('javascript:', $product->description);
        });
    }

    public function test_stock_decrement_is_atomic_and_refuses_to_oversell(): void
    {
        $this->inShop(function () {
            $product = $this->make(['stock_tracked' => true, 'stock_qty' => 1]);
            $catalog = app(ProductCatalog::class);

            $catalog->decrementStock($product->id, 1);

            $this->expectException(InsufficientStock::class);

            $catalog->decrementStock($product->id, 1);
        });
    }

    public function test_a_backorder_product_may_go_below_zero(): void
    {
        $this->inShop(function () {
            $product = $this->make([
                'stock_tracked' => true,
                'stock_qty' => 0,
                'stock_policy' => Product::STOCK_POLICY_BACKORDER,
            ]);

            app(ProductCatalog::class)->decrementStock($product->id, 2);

            $this->assertSame(-2, $product->fresh()->stock_qty);
        });
    }

    public function test_an_untracked_product_never_runs_out(): void
    {
        $this->inShop(function () {
            $product = $this->make(['stock_tracked' => false, 'stock_qty' => 0]);

            app(ProductCatalog::class)->decrementStock($product->id, 99);

            $this->assertSame(0, $product->fresh()->stock_qty);
        });
    }

    public function test_the_contract_finds_an_active_product_by_slug(): void
    {
        $this->inShop(function () {
            $product = $this->make(['status' => Product::STATUS_ACTIVE]);

            $this->assertSame(
                $product->id,
                app(ProductCatalog::class)->findBySlug('notebook-acme-14')?->id
            );
        });
    }

    public function test_the_contract_does_not_hand_out_drafts(): void
    {
        // The storefront reads through this contract. A draft leaking into it
        // publishes an unfinished product with a real, indexable URL.
        $this->inShop(function () {
            $this->make(['status' => Product::STATUS_DRAFT]);

            $this->assertNull(app(ProductCatalog::class)->findBySlug('notebook-acme-14'));
        });
    }

    public function test_search_matches_name_sku_and_short_description(): void
    {
        $this->inShop(function () {
            $this->make(['status' => Product::STATUS_ACTIVE, 'sku' => 'NB-14-ACME']);
            $this->make(['name' => 'Myš bezdrátová', 'status' => Product::STATUS_ACTIVE]);

            $catalog = app(ProductCatalog::class);

            $this->assertCount(1, $catalog->search('NB-14'));
            $this->assertCount(1, $catalog->search('Myš'));
        });
    }

    public function test_deleting_a_product_is_a_soft_delete(): void
    {
        // Orders keep a snapshot, but the foreign key has to stay valid
        // (spec §16.1).
        $this->inShop(function () {
            $product = $this->make();

            $product->delete();

            $this->assertSoftDeleted('products', ['id' => $product->id]);
            $this->assertNull(app(ProductCatalog::class)->findBySlug($product->slug));
        });
    }

    public function test_a_product_belongs_to_categories_with_one_primary(): void
    {
        $this->inShop(function () {
            $tree = app(CategoryTree::class);
            $electronics = $tree->create(['name' => 'Elektronika']);
            $laptops = $tree->create(['name' => 'Notebooky'], $electronics);

            $product = $this->make();
            $this->writer->syncCategories($product, [$electronics->id, $laptops->id], $laptops->id);

            $this->assertSame($laptops->id, $product->fresh()->primaryCategory()?->id);
            $this->assertCount(2, $product->fresh()->categories);
        });
    }

    public function test_a_category_from_another_shop_cannot_be_attached(): void
    {
        $other = Tenant::factory()->create();
        $this->activateModule($other, 'categories');

        $foreign = $this->context->runAs(
            $other,
            fn () => app(CategoryTree::class)->create(['name' => 'Cizí'])
        );

        $this->inShop(function () use ($foreign) {
            $product = $this->make();

            $this->writer->syncCategories($product, [$foreign->id], null);

            $this->assertCount(0, $product->fresh()->categories);
        });
    }

    public function test_products_do_not_cross_between_shops(): void
    {
        $other = Tenant::factory()->create();
        $this->activateModule($other, 'products');

        $this->inShop(fn () => $this->make());

        $this->assertSame(
            0,
            $this->context->runAs($other, fn () => Product::query()->count())
        );
    }

    public function test_the_product_limit_of_the_plan_is_counted(): void
    {
        // Five, not one: the service warns from 80 % of the cap, so a cap of
        // one would never produce a plain Allow and the test would prove
        // nothing about counting.
        $plan = Plan::factory()->create(['limits' => ['products' => 5]]);
        $this->tenant->forceFill(['plan_id' => $plan->id])->save();
        $this->tenant->unsetRelation('plan');

        $this->inShop(function () {
            $limits = app(LimitsService::class);

            $this->assertSame(LimitOutcome::Allow, $limits->check('products')->outcome);

            foreach (range(1, 5) as $i) {
                $this->make(['name' => 'Produkt '.$i]);
            }

            $this->assertSame(5, $limits->usage('products'));
            $this->assertSame(LimitOutcome::Block, $limits->check('products')->outcome);
        });
    }

    public function test_a_soft_deleted_product_stops_counting_against_the_limit(): void
    {
        $plan = Plan::factory()->create(['limits' => ['products' => 1]]);
        $this->tenant->forceFill(['plan_id' => $plan->id])->save();
        $this->tenant->unsetRelation('plan');

        $this->inShop(function () {
            $product = $this->make();

            $this->assertSame(1, app(LimitsService::class)->usage('products'));

            $product->delete();

            $this->assertSame(0, app(LimitsService::class)->usage('products'));
        });
    }

    public function test_changing_the_slug_records_a_redirect(): void
    {
        $this->inShop(function () {
            $product = $this->make();

            $this->writer->update($product, ['slug' => 'notebook-acme-14-pro']);

            $this->assertDatabaseHas('redirects', [
                'from_path' => '/produkt/notebook-acme-14',
                'to_path' => '/produkt/notebook-acme-14-pro',
            ]);
        });
    }

    public function test_a_manufacturer_is_reused_not_duplicated(): void
    {
        $this->inShop(function () {
            $first = $this->writer->manufacturer('Acme');
            $second = $this->writer->manufacturer('acme');

            $this->assertSame($first->id, $second->id);
        });
    }

    public function test_categories_of_a_product_are_kept_when_a_category_is_deleted(): void
    {
        $this->inShop(function () {
            $tree = app(CategoryTree::class);
            $category = $tree->create(['name' => 'Dočasná']);
            $product = $this->make();
            $this->writer->syncCategories($product, [$category->id], $category->id);

            $tree->delete($category);

            // The link goes, the product stays. Losing the product because a
            // grouping was reorganised would be catastrophic.
            $this->assertDatabaseHas('products', ['id' => $product->id]);
            $this->assertCount(0, $product->fresh()->categories);
            $this->assertNull(Category::query()->find($category->id));
        });
    }
}
