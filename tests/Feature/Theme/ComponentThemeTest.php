<?php

namespace Tests\Feature\Theme;

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
 * The design system (Task 4: .btn/.card/.field-input) must actually land on
 * the storefront chrome and shared components, without losing the content
 * or the accessibility hooks the storefront rule requires.
 */
class ComponentThemeTest extends TestCase
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

        foreach (['categories', 'products', 'storefront'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function makeProduct(array $attributes = [], ?Category $category = null): Product
    {
        return $this->context->runAs($this->tenant, function () use ($attributes, $category) {
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

    private function makeCategory(array $attributes = [], ?Category $parent = null): Category
    {
        return $this->context->runAs(
            $this->tenant,
            fn () => app(CategoryTree::class)->create(['name' => 'Notebooky', 'is_visible' => true, ...$attributes], $parent)
        );
    }

    public function test_category_listing_applies_the_design_system_to_the_product_card(): void
    {
        $category = $this->makeCategory(['slug' => 'notebooky']);
        $this->makeProduct(['name' => 'Notebook Acme 14', 'slug' => 'notebook-acme-14', 'price' => 24_990_00], $category);

        $html = $this->get('http://shop1.droidshop/kategorie/notebooky')->assertOk()->getContent();

        // Design system applied: the card wrapper and a button-styled action.
        $this->assertStringContainsString('card', $html);
        $this->assertStringContainsString('btn', $html);

        // Storefront rule: content must not be lost when restyling.
        $this->assertStringContainsString('Notebook Acme 14', $html);
        $this->assertMatchesRegularExpression('/24\s?990/u', $html);
    }

    public function test_breadcrumbs_keep_their_accessible_name(): void
    {
        $category = $this->makeCategory(['slug' => 'notebooky']);
        $this->makeProduct(['slug' => 'notebook-acme-14'], $category);

        $html = $this->get('http://shop1.droidshop/kategorie/notebooky')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Drobečková navigace"', $html);
    }

    public function test_header_search_and_nav_keep_their_accessible_hooks(): void
    {
        $html = $this->get('http://shop1.droidshop/')->assertOk()->getContent();

        $this->assertStringContainsString('role="search"', $html);
        $this->assertStringContainsString('field-input', $html);
        $this->assertStringContainsString('btn-primary', $html);
    }

    public function test_sort_form_uses_the_design_system_field_input(): void
    {
        $category = $this->makeCategory(['slug' => 'notebooky']);
        $this->makeProduct(['slug' => 'notebook-acme-14'], $category);

        $html = $this->get('http://shop1.droidshop/kategorie/notebooky')->assertOk()->getContent();

        $this->assertStringContainsString('field-input', $html);
    }

    public function test_homepage_applies_the_design_system_to_the_product_grid(): void
    {
        $this->makeProduct(['name' => 'Notebook Acme 14', 'slug' => 'notebook-acme-14', 'price' => 24_990_00]);

        $html = $this->get('http://shop1.droidshop/')->assertOk()->getContent();

        $this->assertStringContainsString('card', $html);
        $this->assertStringContainsString('btn', $html);

        // Storefront rule: content survives the restyle.
        $this->assertStringContainsString('Notebook Acme 14', $html);
        $this->assertMatchesRegularExpression('/24\s?990/u', $html);
    }

    public function test_homepage_is_a_clean_default_not_a_page_builder(): void
    {
        $category = $this->makeCategory(['slug' => 'notebooky']);
        $this->makeProduct(['slug' => 'notebook-acme-14'], $category);

        $html = $this->get('http://shop1.droidshop/')->assertOk()->getContent();

        // A simple intro plus a link into the catalogue — no configurable
        // block markup (that is wave 2.3), just the shop name and a CTA.
        $this->assertStringContainsString('Shop One', $html);
        $this->assertStringContainsString('Celá nabídka', $html);
    }

    public function test_product_detail_uses_the_primary_button_and_prose_class(): void
    {
        $this->activateModule($this->tenant, 'checkout');

        $category = $this->makeCategory(['slug' => 'notebooky']);
        $this->makeProduct([
            'slug' => 'notebook-acme-14',
            'description' => '<p>Skvělý notebook pro práci i hry.</p>',
        ], $category);

        $html = $this->get('http://shop1.droidshop/produkt/notebook-acme-14')->assertOk()->getContent();

        $this->assertStringContainsString('btn-primary', $html);
        $this->assertStringContainsString('prose-shop', $html);

        // Storefront rule: name, price and description stay in the raw HTML,
        // and "add to cart" is a plain form that works without JavaScript.
        $this->assertStringContainsString('Notebook Acme 14', $html);
        $this->assertMatchesRegularExpression('/24\s?990/u', $html);
        $this->assertStringContainsString('Skvělý notebook pro práci i hry.', $html);
        $this->assertStringContainsString('<form method="POST" action="http://shop1.droidshop/kosik"', $html);
    }

    public function test_search_results_use_the_design_system_grid(): void
    {
        $this->makeProduct(['name' => 'Notebook Acme 14', 'slug' => 'notebook-acme-14']);

        $html = $this->get('http://shop1.droidshop/hledani?q=acme')->assertOk()->getContent();

        $this->assertStringContainsString('card', $html);
        $this->assertStringContainsString('Notebook Acme 14', $html);
    }

    public function test_not_found_page_uses_the_design_system(): void
    {
        $html = $this->get('http://shop1.droidshop/neexistuje')->assertNotFound()->getContent();

        $this->assertStringContainsString('btn-primary', $html);
        $this->assertStringContainsString('Stránka nenalezena', $html);
    }
}
