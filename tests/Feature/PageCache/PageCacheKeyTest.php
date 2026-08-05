<?php

namespace Tests\Feature\PageCache;

use App\Core\Catalog\ProductQuery;
use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Core\PageCache\PageCacheKey;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PageCacheKeyTest extends TestCase
{
    use RefreshDatabase;

    private PageCacheKey $keys;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->keys = app(PageCacheKey::class);
    }

    private function key(Tenant $tenant, string $uri): string
    {
        return $this->keys->for(Request::create($uri), $tenant, [Dimension::Catalog]);
    }

    public function test_the_key_carries_tenant_host_generation_and_path(): void
    {
        $tenant = Tenant::factory()->create();

        // Request::create() with a bare path defaults the host to localhost.
        $this->assertSame(
            'page:'.$tenant->id.':localhost:1:/kategorie/boty',
            $this->key($tenant, '/kategorie/boty'),
        );
    }

    /**
     * Whole-branch review, finding 3a. A tenant's subdomain and their custom
     * domain both serve the storefront between verification and promotion:
     * DomainTenantFinder resolves on `verified_at`, while
     * RedirectToCanonicalHost only redirects once the primary domain is
     * already the custom one, and promotion waits on a certificate that can
     * fail terminally. Cached HTML carries absolute host-derived URLs
     * (canonical, og:url, the add-to-cart form action), so sharing one entry
     * between the two hosts hands the loser a foreign canonical and an
     * add-to-cart that 419s on a host with no matching session.
     */
    public function test_two_hosts_of_one_tenant_never_share_a_key(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNotSame(
            $this->key($tenant, 'http://obchod.droidshop/kategorie/boty'),
            $this->key($tenant, 'http://obchod.cz/kategorie/boty'),
        );
    }

    /**
     * Whole-branch review, finding 3b. `cache.key` is varchar(255) and the
     * database store is the shipped default, so an unbounded path turns a
     * would-be 404 into a write error.
     */
    public function test_a_very_long_path_produces_a_bounded_key(): void
    {
        $tenant = Tenant::factory()->create();

        $key = $this->key($tenant, '/produkt/'.str_repeat('a', 5000));

        $this->assertLessThan(255, strlen($key));
    }

    public function test_bounding_a_long_path_still_keeps_two_of_them_apart(): void
    {
        $tenant = Tenant::factory()->create();

        // Same 4000-character prefix, different tail: the hash is what keeps
        // them from collapsing onto one entry once the verbatim part is cut.
        $prefix = '/produkt/'.str_repeat('a', 4000);

        $this->assertNotSame(
            $this->key($tenant, $prefix.'-jedna'),
            $this->key($tenant, $prefix.'-dva'),
        );
    }

    public function test_a_short_path_stays_readable_in_the_key(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertStringContainsString('/kategorie/boty', $this->key($tenant, '/kategorie/boty'));
    }

    public function test_two_tenants_never_share_a_key(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->assertNotSame($this->key($a, '/kategorie/boty'), $this->key($b, '/kategorie/boty'));
    }

    public function test_bumping_the_generation_changes_the_key(): void
    {
        $tenant = Tenant::factory()->create();
        $before = $this->key($tenant, '/kategorie/boty');

        app(Generations::class)->bump($tenant, Dimension::Catalog);

        $this->assertNotSame($before, $this->key($tenant, '/kategorie/boty'));
    }

    public function test_unknown_query_parameters_are_dropped(): void
    {
        $tenant = Tenant::factory()->create();

        // Marketing parameters must not fragment the cache: the application
        // ignores them (ProductQuery::fromInput drops what it does not know),
        // so the key may ignore them too.
        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?utm_source=fb&fbclid=xyz'),
        );
    }

    public function test_whitelisted_parameters_do_change_the_key(): void
    {
        $tenant = Tenant::factory()->create();

        // Valid sort values produce different keys
        $this->assertNotSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?razeni=cena-asc'),
        );

        // Page parameter: page > 1 produces different key
        $this->assertNotSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?page=2'),
        );
    }

    public function test_parameter_order_does_not_matter(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(
            $this->key($tenant, '/kategorie/boty?razeni=cena-asc&skladem=1'),
            $this->key($tenant, '/kategorie/boty?skladem=1&razeni=cena-asc'),
        );
    }

    public function test_array_shaped_razeni_parameters_are_ignored(): void
    {
        $tenant = Tenant::factory()->create();

        // ?razeni[]=a is not a valid scalar value, so it's ignored (razeni only
        // accepts whitelisted scalar sorts). It must land on the bare URL key.
        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?razeni[]=a&razeni[]=b'),
        );
    }

    public function test_unrecognised_razeni_value_lands_on_same_key_as_default(): void
    {
        $tenant = Tenant::factory()->create();

        // Invalid sort values fall back to SORT_NEWEST in ProductQuery::fromInput(),
        // so they must land on the same cache key as no sort parameter.
        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?razeni=invalid-sort'),
        );
    }

    public function test_different_recognised_razeni_values_change_the_key(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNotSame(
            $this->key($tenant, '/kategorie/boty?razeni=cena-asc'),
            $this->key($tenant, '/kategorie/boty?razeni=cena-desc'),
        );
    }

    public function test_skladem_bool_variants_land_on_same_key(): void
    {
        $tenant = Tenant::factory()->create();

        // filter_var normalizes all truthy values to bool true.
        $this->assertSame(
            $this->key($tenant, '/kategorie/boty?skladem=1'),
            $this->key($tenant, '/kategorie/boty?skladem=true'),
        );

        $this->assertSame(
            $this->key($tenant, '/kategorie/boty?skladem=1'),
            $this->key($tenant, '/kategorie/boty?skladem=yes'),
        );
    }

    public function test_skladem_false_lands_on_same_key_as_absent(): void
    {
        $tenant = Tenant::factory()->create();

        // False is the default; only true values are included in the key.
        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?skladem=0'),
        );

        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?skladem=false'),
        );
    }

    public function test_search_term_is_trimmed(): void
    {
        $tenant = Tenant::factory()->create();

        // Leading/trailing whitespace is trimmed in ProductQuery::fromInput().
        $this->assertSame(
            $this->key($tenant, '/hledani?q=boty'),
            $this->key($tenant, '/hledani?q=%20boty%20'),
        );
    }

    public function test_empty_search_term_lands_on_same_key_as_absent(): void
    {
        $tenant = Tenant::factory()->create();

        // Empty search terms (after trim) are treated as no search.
        $this->assertSame(
            $this->key($tenant, '/hledani'),
            $this->key($tenant, '/hledani?q='),
        );

        $this->assertSame(
            $this->key($tenant, '/hledani'),
            $this->key($tenant, '/hledani?q=%20'),
        );
    }

    public function test_page_one_lands_on_same_key_as_absent(): void
    {
        $tenant = Tenant::factory()->create();

        // Page 1 is the default; only page > 1 is included in the key.
        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?page=1'),
        );
    }

    public function test_different_page_values_change_the_key(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNotSame(
            $this->key($tenant, '/kategorie/boty?page=2'),
            $this->key($tenant, '/kategorie/boty?page=3'),
        );
    }

    public function test_strana_parameter_is_ignored(): void
    {
        $tenant = Tenant::factory()->create();

        // `strana` is not in the whitelist anymore; it should be treated like
        // marketing parameters and dropped from the key entirely.
        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?strana=2'),
        );

        $this->assertSame(
            $this->key($tenant, '/kategorie/boty?strana=2'),
            $this->key($tenant, '/kategorie/boty?strana=999'),
        );
    }

    public function test_invalid_page_values_land_on_same_key_as_absent(): void
    {
        $tenant = Tenant::factory()->create();

        // filter_var($value, FILTER_VALIDATE_INT) rejects non-integer formats.
        // Laravel's paginator falls back to page 1 for invalid values.
        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?page=2.0'),
        );

        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?page=2abc'),
        );

        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?page=2e0'),
        );
    }

    public function test_invalid_page_differs_from_valid_page(): void
    {
        $tenant = Tenant::factory()->create();

        // Valid page 2 produces a different key than invalid page values.
        $this->assertNotSame(
            $this->key($tenant, '/kategorie/boty?page=2'),
            $this->key($tenant, '/kategorie/boty?page=2.0'),
        );
    }

    public function test_search_term_folds_case_the_way_the_search_does(): void
    {
        $tenant = Tenant::factory()->create();

        // Modules\Products\Support\SearchText::normalise() lower-cases before
        // matching, so `bunda`, `Bunda` and `BUNDA` all find the same
        // products. The key must fold the same way, or each casing mints its
        // own cache entry off one public URL with no authentication.
        $lower = $this->key($tenant, '/hledani?q=bunda');

        $this->assertSame($lower, $this->key($tenant, '/hledani?q=Bunda'));
        $this->assertSame($lower, $this->key($tenant, '/hledani?q=BUNDA'));
    }

    public function test_search_term_folds_diacritics_the_way_the_search_does(): void
    {
        $tenant = Tenant::factory()->create();

        // SearchText::normalise() transliterates through Str::ascii(), so
        // "bundá" and "bunda" find the same products.
        $this->assertSame(
            $this->key($tenant, '/hledani?q=bunda'),
            $this->key($tenant, '/hledani?q=bund%C3%A1'),
        );
    }

    public function test_search_term_case_folding_does_not_regress_trimming(): void
    {
        $tenant = Tenant::factory()->create();

        // The existing trim behaviour (test_search_term_is_trimmed) must
        // survive alongside the new case/diacritic fold.
        $this->assertSame(
            $this->key($tenant, '/hledani?q=bunda'),
            $this->key($tenant, '/hledani?q=%20bunda%20'),
        );
    }

    public function test_genuinely_different_search_terms_still_change_the_key(): void
    {
        $tenant = Tenant::factory()->create();

        // Folding must never collapse two terms the search treats as
        // different products.
        $this->assertNotSame(
            $this->key($tenant, '/hledani?q=bunda'),
            $this->key($tenant, '/hledani?q=kalhoty'),
        );
    }

    public function test_array_shaped_q_parameter_uses_key_suffix(): void
    {
        $tenant = Tenant::factory()->create();

        // ?q[]=a is not a scalar value, so it produces a key with a name suffix.
        // All array-shaped q values share the same key (they all render "Array"),
        // but the key differs from a bare URL and from q=__invalid__.
        $qArrayKey1 = $this->key($tenant, '/hledani?q[]=a');
        $qArrayKey2 = $this->key($tenant, '/hledani?q[]=b');
        $bareKey = $this->key($tenant, '/hledani');
        $invalidKey = $this->key($tenant, '/hledani?q=__invalid__');

        $this->assertSame($qArrayKey1, $qArrayKey2);
        $this->assertNotSame($qArrayKey1, $bareKey);
        $this->assertNotSame($qArrayKey1, $invalidKey);
    }

    public function test_array_shaped_razeni_lands_on_bare_key(): void
    {
        $tenant = Tenant::factory()->create();

        // ?razeni[]=a is not a scalar value. ProductQuery::fromInput() doesn't
        // see it as is_string(), so it falls back to SORT_NEWEST (the default).
        // Therefore array-shaped razeni must land on the same key as the bare URL.
        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?razeni[]=a'),
        );

        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?razeni[]=b'),
        );
    }
}
