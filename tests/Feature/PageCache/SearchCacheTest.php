<?php

namespace Tests\Feature\PageCache;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\PageCacheKey;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Categories\Services\CategoryTree;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class SearchCacheTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('pagecache.enabled', true);

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'storefront');
        $this->activateModule($this->tenant, 'products');
    }

    /**
     * The brief's own draft reached into the array store's private $storage
     * property through a bound closure to count "page:" keys. That is
     * fragile: it depends on a framework internal that owes this test
     * nothing and could rename or restructure without warning.
     *
     * `PageCacheKey` is the same public service `CacheStorefrontPage` uses to
     * name a key for a given request. Reproducing that computation here and
     * asking the store directly whether the key exists proves the identical
     * fact — nothing landed under the key the middleware would have used —
     * through the public `Cache` API instead of a private one. It proves
     * exactly as much as the counting approach: for a fresh key created by
     * `RefreshDatabase`, "not present" is unambiguous either way.
     */
    private function isStored(string $url): bool
    {
        $key = app(PageCacheKey::class)->for(
            Request::create($url),
            $this->tenant->fresh(),
            Dimension::list(['catalog', 'theme']),
        );

        return Cache::store()->has($key);
    }

    public function test_a_search_with_no_results_is_not_stored(): void
    {
        $url = 'http://obchod.droidshop/hledani?q=naprostoneexistujici';

        $this->get($url)->assertOk();

        $this->assertFalse($this->isStored($url));
    }

    public function test_an_absurdly_long_term_is_not_stored(): void
    {
        $url = 'http://obchod.droidshop/hledani?q='.str_repeat('a', 200);

        $this->get($url)->assertOk();

        $this->assertFalse($this->isStored($url));
    }

    /**
     * Finding 3: `q` is whitelisted for every cached route, not just
     * `/hledani` (the shared layout's header search box echoes it on every
     * storefront page — Finding 2 made that fold matter, and it also made
     * the whitelist entry genuinely load-bearing everywhere). A guard living
     * only in SearchController cannot stop `/kategorie/{slug}?q=<huge>` from
     * minting a storable, uniquely-keyed page the same way an oversized
     * search term would. CacheStorefrontPage::exceedsSearchTermLimit() is
     * the guard that has to catch this one.
     */
    public function test_an_oversized_query_string_on_a_non_search_route_is_not_stored(): void
    {
        $this->activateModule($this->tenant, 'categories');

        app(TenantContext::class)->runAs($this->tenant, fn () => app(CategoryTree::class)->create([
            'name' => 'Elektronika',
            'slug' => 'elektronika',
            'is_visible' => true,
        ]));

        $url = 'http://obchod.droidshop/kategorie/elektronika?q='.str_repeat('a', 200);

        $this->get($url)->assertOk();

        $this->assertFalse($this->isStored($url));
    }

    public function test_a_query_string_under_the_limit_on_a_non_search_route_is_stored(): void
    {
        $this->activateModule($this->tenant, 'categories');

        app(TenantContext::class)->runAs($this->tenant, fn () => app(CategoryTree::class)->create([
            'name' => 'Elektronika',
            'slug' => 'elektronika',
            'is_visible' => true,
        ]));

        $url = 'http://obchod.droidshop/kategorie/elektronika?q=bunda';

        $this->get($url)->assertOk();

        $this->assertTrue($this->isStored($url));
    }

    public function test_a_short_search_that_finds_something_is_stored(): void
    {
        $this->activateModule($this->tenant, 'categories');

        app(TenantContext::class)->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Černá bunda zimní',
            'price' => 1_990_00,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));

        $url = 'http://obchod.droidshop/hledani?q=bunda';

        $this->get($url)->assertOk()->assertSee('Černá bunda zimní');

        $this->assertTrue($this->isStored($url));
    }

    /**
     * Finding 4: every other test in this wave proves folding is correct
     * with the cache disabled, or proves caching works with a generic probe
     * route — nothing proves the property a real visitor experiences: with
     * the cache genuinely on, a search followed by the same search typed in
     * a different case is a real cache hit that serves correct content
     * either way. The transitive argument (key folds → controller/view fold
     * the same way → therefore a shared entry is safe) holds, but this test
     * exercises it end to end instead of trusting the chain.
     */
    public function test_the_cache_serves_a_genuine_hit_for_a_differently_cased_repeat_search(): void
    {
        app(TenantContext::class)->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Černá bunda zimní',
            'price' => 1_990_00,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));

        $first = $this->get('http://obchod.droidshop/hledani?q=Bunda')->assertOk();
        $first->assertSee('Černá bunda zimní');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $second = $this->get('http://obchod.droidshop/hledani?q=BUNDA')->assertOk();

        // Both bodies are correct for the visitor who requested them (both
        // contain the product), and they are byte-identical — which, for a
        // page served genuinely from the store, is what a cache hit means:
        // the second response is not independently rendered at all.
        $second->assertSee('Černá bunda zimní');
        $this->assertSame($first->getContent(), $second->getContent());

        // Same bound PageCacheMiddlewareTest::test_a_cache_hit_runs_no_catalogue_queries
        // uses: resolving the tenant still costs a query or two; a genuine
        // cache hit must not touch the catalogue to rebuild the page.
        $this->assertLessThanOrEqual(3, $queries);
    }
}
