<?php

namespace Tests\Feature\Storefront;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Models\Category;
use Modules\Categories\Services\CategoryTree;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductAttribute;
use Modules\Products\Services\AttributeWriter;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The filter panel as a customer and a crawler meet it (wave 4.2, task B3).
 */
class StorefrontFacetTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private Category $category;

    private ProductAttribute $colour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();

        foreach (['categories', 'products', 'storefront'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->context->runAs($this->tenant, function (): void {
            $this->category = app(CategoryTree::class)->create(['name' => 'Obrazy', 'is_visible' => true]);

            $this->colour = app(AttributeWriter::class)->create(['name' => 'Barva']);

            foreach (['Modrá', 'Černá'] as $value) {
                app(AttributeWriter::class)->addValue($this->colour, ['value' => $value]);
            }

            $this->colour = $this->colour->fresh();
        });
    }

    private function product(string $name, string $valueSlug): void
    {
        $this->context->runAs($this->tenant, function () use ($name, $valueSlug): void {
            $product = app(ProductWriter::class)->create([
                'name' => $name,
                'price' => 42900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            app(ProductWriter::class)->syncCategories($product, [$this->category->id], $this->category->id);

            $value = $this->colour->values->firstWhere('slug', $valueSlug);
            app(AttributeWriter::class)->syncForProduct($product, [$value->id]);
        });
    }

    private function url(array $query = []): string
    {
        $path = 'http://obchod.droidshop/kategorie/'.$this->category->slug;

        return $query === [] ? $path : $path.'?'.http_build_query($query);
    }

    public function test_the_panel_is_a_plain_get_form(): void
    {
        $this->product('Modrý obraz', 'modra');

        $html = (string) $this->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('<form method="get"', $html);
        $this->assertStringContainsString('name="vlastnost[barva][]"', $html);
        $this->assertStringContainsString('Barva', $html);
    }

    public function test_a_filter_narrows_the_listing_over_plain_http(): void
    {
        $this->product('Modrý obraz', 'modra');
        $this->product('Černý obraz', 'cerna');

        $this->get($this->url(['vlastnost' => ['barva' => ['modra']]]))
            ->assertOk()
            ->assertSee('Modrý obraz')
            ->assertDontSee('Černý obraz');
    }

    public function test_a_filtered_page_is_noindex_and_points_home(): void
    {
        // Filtered combinations are the same goods sliced differently. Left
        // indexable they compete with the category itself and multiply into
        // thousands of near-identical URLs.
        $this->product('Modrý obraz', 'modra');

        $html = (string) $this->get($this->url(['vlastnost' => ['barva' => ['modra']]]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('noindex', $html);
        $this->assertStringContainsString(
            '<link rel="canonical" href="http://obchod.droidshop/kategorie/'.$this->category->slug.'"',
            $html,
        );
    }

    public function test_the_unfiltered_listing_stays_indexable(): void
    {
        $this->product('Modrý obraz', 'modra');

        $html = (string) $this->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('index, follow', $html);
    }

    public function test_a_shop_without_the_products_module_renders_no_panel(): void
    {
        // The listing asks a kernel contract, and the kernel's default answers
        // "no filters" — so the page loses its panel instead of failing.
        $other = Tenant::factory()->withDomain('bezproduktu.droidshop')->create();
        $this->activateModule($other, 'categories');
        $this->activateModule($other, 'storefront');

        $category = $this->context->runAs(
            $other,
            fn () => app(CategoryTree::class)->create(['name' => 'Obrazy', 'is_visible' => true]),
        );

        $this->context->forget();

        $this->get('http://bezproduktu.droidshop/kategorie/'.$category->slug)
            ->assertOk()
            ->assertDontSee('name="vlastnost', false);
    }
}
