<?php

namespace Tests\Feature\Theme;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Models\Category;
use Modules\Categories\Services\CategoryTree;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Storefront\Support\DefaultHomepage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * What every theme owes the shop, whatever it looks like (wave 4.1, task 5).
 *
 * The provider walks the registry, so a theme joins this suite by existing.
 * That is the whole reason a theme can be added by deploying a directory: the
 * things a storefront must not lose — server-rendered prices, a canonical, the
 * structured data a comparison engine reads — are asserted once and hold for
 * anything anyone lays out later.
 */
class ThemeStorefrontContractTest extends TestCase
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

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create(['name' => 'Obchod']);

        foreach (['categories', 'products', 'storefront', 'checkout', 'pages'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        app(DefaultHomepage::class)->seed($this->tenant);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function themes(): array
    {
        $cases = [];

        // Not base_path(): PHPUnit builds data providers before the
        // application is booted, so the helper is not available yet.
        foreach (glob(dirname(__DIR__, 3).'/themes/*/theme.json') ?: [] as $file) {
            $key = basename(dirname($file));
            $cases[$key] = [$key];
        }

        return $cases;
    }

    private function shopWith(string $theme): Category
    {
        TenantTheme::updateOrCreate(['tenant_id' => $this->tenant->id], ['template' => $theme]);

        // The middleware caches the lookup against the theme generation, and
        // updateOrCreate just bumped it, so nothing has to be forgotten here.
        $this->assertSame($theme, app(ThemeRegistry::class)->find($theme)->key);

        $category = $this->context->runAs($this->tenant, fn () => app(CategoryTree::class)->create([
            'name' => 'Notebooky',
            'is_visible' => true,
        ]));

        $this->context->runAs($this->tenant, function () use ($category): void {
            $product = app(ProductWriter::class)->create([
                'name' => 'Notebook Acme 14',
                'price' => 24_990_00,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'description' => 'Lehký notebook pro každodenní práci.',
            ]);

            app(ProductWriter::class)->syncCategories($product, [$category->id], $category->id);
        });

        $this->context->forget();

        return $category;
    }

    /**
     * The price has to be in the server's HTML, not computed later by script.
     *
     * Matched by pattern rather than by literal: ICU puts a narrow no-break
     * space between the groups, and asserting on a plain space would pass only
     * by accident.
     */
    private function assertPriceIsRendered(string $html): void
    {
        $this->assertMatchesRegularExpression('/24[\s\x{00a0}\x{202f}]?990/u', $html);
    }

    #[DataProvider('themes')]
    public function test_the_product_page_carries_its_own_content_in_the_html(string $theme): void
    {
        $this->shopWith($theme);

        $response = $this->get('http://obchod.droidshop/produkt/notebook-acme-14')->assertOk();

        $response->assertSee('Notebook Acme 14');
        $response->assertSee('Lehký notebook pro každodenní práci.', false);
        $this->assertPriceIsRendered((string) $response->getContent());
    }

    #[DataProvider('themes')]
    public function test_the_product_page_carries_seo_markup(string $theme): void
    {
        $this->shopWith($theme);

        $html = (string) $this->get('http://obchod.droidshop/produkt/notebook-acme-14')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '<link rel="canonical" href="http://obchod.droidshop/produkt/notebook-acme-14"',
            $html,
        );
        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringContainsString('"@type":"Offer"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
    }

    #[DataProvider('themes')]
    public function test_the_category_listing_shows_names_and_prices(string $theme): void
    {
        $category = $this->shopWith($theme);

        $response = $this->get("http://obchod.droidshop/kategorie/{$category->slug}")
            ->assertOk()
            ->assertSee('Notebook Acme 14');

        $this->assertPriceIsRendered((string) $response->getContent());
    }

    #[DataProvider('themes')]
    public function test_the_homepage_and_search_render(string $theme): void
    {
        $this->shopWith($theme);

        $this->get('http://obchod.droidshop/')->assertOk()->assertSee('Obchod');
        $this->get('http://obchod.droidshop/hledani?q=notebook')->assertOk()->assertSee('Notebook Acme 14');
    }

    #[DataProvider('themes')]
    public function test_the_cart_renders_under_the_themes_layout(string $theme): void
    {
        // The cart is never overridable, so this is not about how it looks —
        // it is that a theme's layout still hosts it. A theme that broke the
        // layout would take the checkout down with it.
        $this->shopWith($theme);

        $this->get('http://obchod.droidshop/kosik')->assertOk();
    }

    #[DataProvider('themes')]
    public function test_an_unknown_url_returns_a_rendered_404(string $theme): void
    {
        $this->shopWith($theme);

        $this->get('http://obchod.droidshop/produkt/neexistuje')->assertNotFound();
    }

    #[DataProvider('themes')]
    public function test_the_cached_page_carries_no_personal_content(string $theme): void
    {
        // Page cache entries are shared between visitors, so anything personal
        // in the HTML reaches the next anonymous one. The mini-cart is a
        // placeholder in the base layout; a theme that copied the layout and
        // "improved" the header is exactly how that guarantee gets lost.
        $this->shopWith($theme);

        $html = (string) $this->get('http://obchod.droidshop/')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-cart-count="', $html);
    }
}
