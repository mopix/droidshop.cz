<?php

namespace Tests\Feature\Storefront;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Products\Support\SearchText;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Storefront search (spec §4.1, §16.1).
 *
 * Czech is the whole reason the normalised column exists: without folding,
 * "cerna bunda" finds nothing and the shop looks broken.
 */
class StorefrontSearchTest extends TestCase
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

    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Černá bunda zimní',
            'price' => 1_990_00,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            ...$attributes,
        ]));
    }

    public function test_normalisation_folds_case_and_diacritics(): void
    {
        $this->assertSame('cerna bunda zimni', SearchText::normalise('Černá bunda zimní'));
        $this->assertSame('cerna bunda acme-1', SearchText::normalise('Černá bunda', 'ACME-1'));
        $this->assertSame('popis', SearchText::normalise('<p>Popis</p>'));
    }

    public function test_search_finds_a_product_written_without_diacritics(): void
    {
        $this->makeProduct($this->tenantA);

        $this->get('http://shop1.droidshop/hledani?q=cerna')
            ->assertOk()
            ->assertSee('Černá bunda zimní');
    }

    public function test_search_finds_a_product_by_sku(): void
    {
        $this->makeProduct($this->tenantA, ['sku' => 'ACME-99']);

        $this->get('http://shop1.droidshop/hledani?q=acme-99')
            ->assertOk()
            ->assertSee('Černá bunda zimní');
    }

    public function test_search_does_not_cross_tenants(): void
    {
        $this->makeProduct($this->tenantA, ['name' => 'Tajna bunda']);

        $this->get('http://shop2.droidshop/hledani?q=bunda')
            ->assertOk()
            ->assertDontSee('Tajna bunda');
    }

    public function test_a_one_character_query_asks_for_more_instead_of_listing_everything(): void
    {
        $this->makeProduct($this->tenantA);

        $this->get('http://shop1.droidshop/hledani?q=c')
            ->assertOk()
            ->assertSee('alespoň dva znaky')
            ->assertDontSee('Černá bunda zimní');
    }

    public function test_search_results_are_never_indexed(): void
    {
        $this->makeProduct($this->tenantA);

        $this->get('http://shop1.droidshop/hledani?q=bunda')
            ->assertSee('content="noindex, follow"', false);
    }

    public function test_draft_products_do_not_show_up_in_search(): void
    {
        $this->makeProduct($this->tenantA, ['name' => 'Rozpracovana bunda', 'status' => Product::STATUS_DRAFT]);

        $this->get('http://shop1.droidshop/hledani?q=bunda')
            ->assertOk()
            ->assertDontSee('Rozpracovana bunda');
    }

    public function test_the_heading_shows_the_folded_term_not_the_raw_one(): void
    {
        $this->makeProduct($this->tenantA);

        $response = $this->get('http://shop1.droidshop/hledani?q=BUNDA')->assertOk();

        $response->assertSee('Vyhledávání: bunda', false);
        $response->assertDontSee('Vyhledávání: BUNDA', false);
    }

    public function test_the_canonical_link_carries_the_folded_term(): void
    {
        $this->makeProduct($this->tenantA);

        $this->get('http://shop1.droidshop/hledani?q=BUNDA')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="http://shop1.droidshop/hledani?q=bunda">', false);
    }

    public function test_differently_cased_terms_render_byte_identical_pages(): void
    {
        // This is the property that actually matters: a shared page cache
        // means the HTML is a pure function of the (folded) cache key, so
        // any two requests that fold to the same key must render identical
        // bytes — otherwise whichever visitor's request populated the cache
        // silently dictates what the other one sees.
        //
        // The page cache must be off for this assertion to mean anything.
        // ?q=Bunda and ?q=BUNDA already fold to the same cache key (fixed
        // separately in PageCacheKey), so with the cache on the second
        // request would just replay whatever the first one rendered —
        // "identical bytes" for the wrong reason, true even if this
        // controller/view fix were reverted. Disabling the cache forces both
        // requests to render independently, so the only way they can match
        // is if the controller and view themselves are folding correctly.
        config()->set('pagecache.enabled', false);

        $this->makeProduct($this->tenantA);

        $mixed = $this->get('http://shop1.droidshop/hledani?q=Bunda')->assertOk()->getContent();
        $upper = $this->get('http://shop1.droidshop/hledani?q=BUNDA')->assertOk()->getContent();

        $this->assertSame($mixed, $upper);
    }

    public function test_a_diacritic_term_folds_and_matches_the_plain_term_byte_for_byte(): void
    {
        // See the comment on the case-folding test above: the page cache
        // must be off here for the same reason, or a cache hit on the
        // second request would prove nothing about the controller/view.
        config()->set('pagecache.enabled', false);

        $this->makeProduct($this->tenantA);

        $plain = $this->get('http://shop1.droidshop/hledani?q=bunda')->assertOk()->getContent();
        $accented = $this->get('http://shop1.droidshop/hledani?q=bund%C3%A1')->assertOk()->getContent();

        $this->assertStringContainsString('Vyhledávání: bunda', $accented);
        $this->assertSame($plain, $accented);
    }

    public function test_pagination_links_carry_the_folded_term_not_the_raw_one(): void
    {
        // EloquentProductCatalog::paginate() calls withQueryString(), which
        // captures the raw request query string for its page-2 link. That is
        // a second, easy-to-miss place the raw `q` can leak onto an
        // otherwise-folded page — this is not one of the four cases the
        // review asked for, but the same "pure function of the cache key"
        // principle applies to it, so it is covered here too.
        for ($i = 0; $i < 25; $i++) {
            $this->makeProduct($this->tenantA);
        }

        $content = $this->get('http://shop1.droidshop/hledani?q=BUNDA')->assertOk()->getContent();

        preg_match('/href="([^"]*page=2[^"]*)"/', $content, $matches);

        $this->assertNotEmpty($matches, 'Expected a page-2 pagination link in the response.');
        $this->assertStringContainsString('q=bunda', $matches[1]);
        $this->assertStringNotContainsString('q=BUNDA', $matches[1]);
    }

    public function test_the_header_search_box_trims_the_term_on_a_cached_page(): void
    {
        // PageCacheKey::foldSearchTerm() (the cache key's own fold) trims the
        // term; the header search box on this shared layout must fold the
        // exact same way, or a whitespace-padded query and its trimmed
        // equivalent land on one cache entry while rendering different box
        // contents depending on which request warmed it. Page cache is on
        // here on purpose — this is the scenario the drift actually bites in.
        config()->set('pagecache.enabled', true);

        $this->makeProduct($this->tenantA);

        $content = $this->get('http://shop1.droidshop/hledani?q=%20bunda%20')
            ->assertOk()
            ->getContent();

        preg_match('/id="hledani"[^>]*value="([^"]*)"/s', $content, $matches);

        $this->assertSame('bunda', $matches[1] ?? null);
    }

    public function test_reindex_command_rebuilds_the_column(): void
    {
        $this->makeProduct($this->tenantA);

        // Simulates rows written before the column existed.
        DB::table('products')->update(['search_text' => null]);

        $this->artisan('products:reindex-search')->assertSuccessful();

        $this->get('http://shop1.droidshop/hledani?q=cerna')
            ->assertOk()
            ->assertSee('Černá bunda zimní');
    }
}
