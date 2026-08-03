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
}
