<?php

namespace Tests\Feature\Modules\Pages;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categories\Models\Category;
use Modules\Pages\Models\Page;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Wave 3.1: static pages moved from /stranka/{slug} to /{page-slug}, which
 * the binding storefront rule has always asked for. The mechanism is
 * Route::fallback(), not a catch-all /{slug} and not a blacklist of reserved
 * first segments: Laravel evaluates the fallback after every other route no
 * matter what order the modules registered in (ModuleRouteRegistrar walks
 * glob(), i.e. alphabetically, so `pages` comes before `products` and
 * `storefront`), and it therefore adapts to any storefront route added
 * later. A blacklist would have to be extended by hand every time and would
 * fail silently when it was not.
 */
class PageRoutingTest extends TestCase
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

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'storefront');
        $this->activateModule($this->tenant, 'pages');
    }

    /**
     * updateOrCreate, not create: Modules\Pages\Lifecycle seeds three empty
     * unpublished pages (including `kontakt`) when the module is activated.
     */
    private function publishPage(string $slug = 'kontakt'): Page
    {
        return $this->context->runAs($this->tenant, fn () => Page::query()->updateOrCreate(
            ['slug' => $slug],
            ['title' => 'Kontakt', 'body' => 'Telefon: 123', 'is_published' => true],
        ));
    }

    public function test_a_published_page_answers_at_the_root(): void
    {
        $this->publishPage();

        $this->get('http://obchod.droidshop/kontakt')
            ->assertOk()
            ->assertSee('Kontakt')
            ->assertSee('Telefon: 123');
    }

    public function test_the_old_path_redirects_permanently(): void
    {
        $this->publishPage();

        $this->get('http://obchod.droidshop/stranka/kontakt')
            ->assertStatus(301)
            ->assertRedirect('http://obchod.droidshop/kontakt');
    }

    /**
     * The legacy route does not consult the database on purpose: the redirect
     * holds even for a slug that no longer exists, where the new path answers
     * 404 on its own. A lookup here would only leak which slugs exist.
     */
    public function test_the_old_path_redirects_even_for_an_unknown_slug(): void
    {
        $this->get('http://obchod.droidshop/stranka/nikdy-neexistovala')
            ->assertStatus(301)
            ->assertRedirect('http://obchod.droidshop/nikdy-neexistovala');
    }

    public function test_an_unpublished_page_is_not_served(): void
    {
        $this->context->runAs($this->tenant, fn () => Page::query()->create([
            'slug' => 'koncept',
            'title' => 'Koncept',
            'is_published' => false,
        ]));

        $this->get('http://obchod.droidshop/koncept')->assertNotFound();
    }

    public function test_another_tenants_page_is_not_served(): void
    {
        $other = Tenant::factory()->withDomain('jiny.droidshop')->create();
        $this->activateModule($other, 'storefront');
        $this->activateModule($other, 'pages');
        $this->publishPage();

        $this->get('http://jiny.droidshop/kontakt')->assertNotFound();
    }

    public function test_a_tenant_without_the_module_gets_404(): void
    {
        $other = Tenant::factory()->withDomain('jiny.droidshop')->create();
        $this->activateModule($other, 'storefront');
        $this->publishPage();

        $this->get('http://jiny.droidshop/kontakt')->assertNotFound();
    }

    public function test_the_platform_host_has_no_page_route(): void
    {
        $this->publishPage();

        $this->get('http://droidshop/kontakt')->assertNotFound();
    }

    /**
     * The whole risk of this change in one test: a fallback that swallowed a
     * single-segment storefront path would break it silently. Every path a
     * storefront module registers at the top level is listed here, so adding
     * a colliding one fails loudly.
     */
    #[DataProvider('singleSegmentStorefrontPaths')]
    public function test_a_storefront_path_is_not_swallowed_by_the_fallback(string $path): void
    {
        foreach (['products', 'categories', 'checkout', 'customers', 'feeds'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        // A page whose slug is the same string must not win over the real
        // route either — the fallback only ever runs when nothing matched.
        $this->publishPage(ltrim($path, '/'));

        $response = $this->get('http://obchod.droidshop'.$path);

        $this->assertNotSame(
            'Telefon: 123',
            $response->getContent(),
            $path.' was answered by the page fallback',
        );
        $response->assertDontSee('Telefon: 123');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function singleSegmentStorefrontPaths(): array
    {
        return [
            'cart' => ['/kosik'],
            'search' => ['/hledani'],
            'register' => ['/registrace'],
            'login' => ['/prihlaseni'],
            'account' => ['/ucet'],
            'password request' => ['/zapomenute-heslo'],
            'sitemap' => ['/sitemap.xml'],
            'robots' => ['/robots.txt'],
        ];
    }

    public function test_a_multi_segment_path_is_not_treated_as_a_page(): void
    {
        $this->publishPage();

        $this->get('http://obchod.droidshop/kontakt/neco')->assertNotFound();
        $this->get('http://obchod.droidshop/admin/neexistujici')->assertNotFound();
    }

    /**
     * RedirectResponder hangs off NotFoundHttpException and answers renamed
     * slugs with a 301 (spec §15.3). The fallback must keep falling through
     * to it rather than answering the 404 itself, or every renamed product
     * would lose its redirect.
     */
    public function test_a_renamed_product_slug_still_redirects(): void
    {
        $this->activateModule($this->tenant, 'products');
        $this->activateModule($this->tenant, 'categories');

        $product = $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice',
            'slug' => 'stara-klavesnice',
            'price' => 100000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));

        $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->update($product, [
            'slug' => 'nova-klavesnice',
        ]));

        $this->get('http://obchod.droidshop/produkt/stara-klavesnice')
            ->assertStatus(301)
            ->assertRedirect('http://obchod.droidshop/produkt/nova-klavesnice');
    }

    public function test_a_category_path_still_wins_over_the_fallback(): void
    {
        $this->activateModule($this->tenant, 'categories');
        $this->activateModule($this->tenant, 'products');

        $this->context->runAs($this->tenant, fn () => Category::query()->create([
            'name' => 'Klávesnice',
            'slug' => 'klavesnice',
            'is_visible' => true,
            'position' => 1,
        ]));

        $this->get('http://obchod.droidshop/kategorie/klavesnice')->assertOk();
    }

    public function test_the_page_is_cached_on_the_second_request(): void
    {
        $page = $this->publishPage();

        $this->get('http://obchod.droidshop/kontakt')->assertOk();

        // Written straight through the query builder so no Eloquent event
        // fires and the page-cache generation does not move: a second request
        // must therefore still serve the stored copy.
        Page::query()->whereKey($page->id)->update(['body' => 'Telefon: 999']);

        $this->get('http://obchod.droidshop/kontakt')
            ->assertOk()
            ->assertSee('Telefon: 123')
            ->assertDontSee('Telefon: 999');
    }

    public function test_the_sitemap_lists_the_new_path(): void
    {
        $this->publishPage();

        $this->get('http://obchod.droidshop/sitemap.xml')
            ->assertOk()
            ->assertSee('http://obchod.droidshop/kontakt', escape: false)
            ->assertDontSee('/stranka/kontakt', escape: false);
    }
}
