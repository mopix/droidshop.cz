<?php

namespace Tests\Feature\PageCache;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class RedirectLookupCacheTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();

        foreach (['categories', 'products', 'storefront'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($this->tenant, $callback);
    }

    /**
     * `$this->get()` cannot carry a trailing slash to the application: Laravel's
     * own `MakesHttpRequests::prepareUrlForRequest()` runs `trim($uri, '/')` on
     * every URL before the request is even built, so `.../stary-slug/` and
     * `.../stary-slug` are indistinguishable through the normal test helper.
     * Driving the kernel directly — the same thing `call()` does internally,
     * minus that trim — is the only way to get a genuine trailing slash past
     * the front door and exercise RedirectRegistry's own normalisation.
     */
    private function getPreservingTrailingSlash(string $url): TestResponse
    {
        $request = Request::create($url, 'GET');

        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return $this->inShop(fn () => app(ProductWriter::class)->create([
            'name' => 'Notebook Acme 14',
            'price' => 24_990_00,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            ...$attributes,
        ]));
    }

    public function test_a_repeated_miss_does_not_query_the_redirect_table_again(): void
    {
        $this->get('http://obchod.droidshop/wp-admin/setup-config.php')->assertNotFound();

        $hits = 0;
        DB::listen(function ($query) use (&$hits): void {
            if (str_contains($query->sql, 'redirects')) {
                $hits++;
            }
        });

        $this->get('http://obchod.droidshop/wp-admin/setup-config.php')->assertNotFound();

        // Scanners hammer paths like this. The lookup is pure catalogue data,
        // so it belongs behind the same generation as everything else.
        $this->assertSame(0, $hits);
    }

    public function test_a_trailing_slash_still_resolves_the_redirect(): void
    {
        $product = $this->makeProduct(['slug' => 'stary-slug']);

        $this->inShop(fn () => app(ProductWriter::class)->update($product, ['slug' => 'novy-slug']));

        // Guards the cached lookup's key material: the closure keeps going
        // through RedirectRegistry::resolve() rather than querying from_path
        // directly against whatever $path happens to hold, so a future
        // change to how the registry canonicalises a path (case, repeated
        // slashes, a scheme change to normalise()) cannot silently diverge
        // from what the cache queries for. (Request::path() already strips
        // a trailing slash on its own before $path is built, so this
        // specific input does not currently distinguish the two — this test
        // still documents the contract the cached closure must honour.)
        $this->getPreservingTrailingSlash('http://obchod.droidshop/produkt/stary-slug/')
            ->assertStatus(301)
            ->assertRedirect('http://obchod.droidshop/produkt/novy-slug');
    }

    public function test_a_write_that_creates_a_redirect_invalidates_a_previously_cached_miss(): void
    {
        // The path does not resolve to anything yet — no product, no
        // redirect — so this is a genuine miss, cached under whatever the
        // catalogue generation happens to be right now.
        $this->get('http://obchod.droidshop/produkt/presouvany')->assertNotFound();

        $product = $this->makeProduct(['slug' => 'presouvany']);

        // The rename is the write that actually creates a redirect row whose
        // from_path is the exact path the request above just missed on.
        $this->inShop(fn () => app(ProductWriter::class)->update($product, ['slug' => 'presunuto']));

        // Same path, same cache-eligible request: it must 301 immediately,
        // not serve the miss cached before the redirect existed for the rest
        // of the TTL.
        $this->get('http://obchod.droidshop/produkt/presouvany')
            ->assertStatus(301)
            ->assertRedirect('http://obchod.droidshop/produkt/presunuto');
    }

    /**
     * Whole-branch review, finding 3b. This lookup builds its key from the
     * raw path on *every* unmatched URL — the one scanners hammer — and
     * `cache.key` is varchar(255). An unbounded key is a write error: a 500
     * where the visitor should simply have received a 404. The store is
     * pointed at the database here on purpose; the array store this suite
     * otherwise uses has no column width to overflow.
     */
    public function test_a_very_long_unmatched_path_is_a_404_not_an_error(): void
    {
        config()->set('pagecache.store', 'database');

        $this->get('http://obchod.droidshop/'.str_repeat('a', 4000))->assertNotFound();
    }

    /**
     * The same bound, on the other key: an unknown slug on a real route is an
     * in-route 404, and an in-route 404 is storable by the page cache itself.
     */
    public function test_a_very_long_product_slug_is_a_404_not_an_error(): void
    {
        config()->set('pagecache.store', 'database');

        $this->get('http://obchod.droidshop/produkt/'.str_repeat('a', 4000))->assertNotFound();
    }

    /**
     * Whole-branch review, finding 6. `pagecache.enabled` is the documented
     * emergency brake, and it has to restore pre-wave behaviour everywhere —
     * including this lookup, which ignored it and kept answering from cache.
     */
    public function test_disabling_the_page_cache_takes_the_redirect_lookup_back_to_the_table(): void
    {
        config()->set('pagecache.enabled', false);

        $product = $this->makeProduct(['slug' => 'stary-vypnuty']);
        $this->inShop(fn () => app(ProductWriter::class)->update($product, ['slug' => 'novy-vypnuty']));

        $this->get('http://obchod.droidshop/produkt/stary-vypnuty')
            ->assertStatus(301)
            ->assertRedirect('http://obchod.droidshop/produkt/novy-vypnuty');

        $hits = 0;
        DB::listen(function ($query) use (&$hits): void {
            if (str_contains($query->sql, 'redirects')) {
                $hits++;
            }
        });

        $this->get('http://obchod.droidshop/produkt/stary-vypnuty')->assertStatus(301);

        // With the brake pulled the table is queried again every time, which
        // is exactly the behaviour the switch promises to restore.
        $this->assertGreaterThan(0, $hits);
    }

    public function test_a_repeated_hit_serves_the_same_target_from_the_cache(): void
    {
        $product = $this->makeProduct(['slug' => 'stary']);

        $this->inShop(fn () => app(ProductWriter::class)->update($product, ['slug' => 'novy']));

        $this->get('http://obchod.droidshop/produkt/stary')
            ->assertStatus(301)
            ->assertRedirect('http://obchod.droidshop/produkt/novy');

        // Second request for the same still-current redirect: served from
        // the cached hit, and the target must not have shifted.
        $this->get('http://obchod.droidshop/produkt/stary')
            ->assertStatus(301)
            ->assertRedirect('http://obchod.droidshop/produkt/novy');
    }
}
