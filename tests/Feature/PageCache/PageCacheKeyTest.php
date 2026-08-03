<?php

namespace Tests\Feature\PageCache;

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

    public function test_the_key_carries_tenant_generation_and_path(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(
            'page:'.$tenant->id.':1:/kategorie/boty',
            $this->key($tenant, '/kategorie/boty'),
        );
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

        $this->assertNotSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?razeni=cena-vzestupne'),
        );

        $this->assertNotSame(
            $this->key($tenant, '/kategorie/boty?strana=2'),
            $this->key($tenant, '/kategorie/boty?strana=3'),
        );
    }

    public function test_parameter_order_does_not_matter(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(
            $this->key($tenant, '/kategorie/boty?razeni=cena-vzestupne&skladem=1'),
            $this->key($tenant, '/kategorie/boty?skladem=1&razeni=cena-vzestupne'),
        );
    }

    public function test_array_shaped_parameters_are_ignored(): void
    {
        $tenant = Tenant::factory()->create();

        // ?razeni[]=a&razeni[]=b must not blow up key building or let an
        // attacker mint unbounded distinct keys from one whitelisted name.
        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?razeni[]=a&razeni[]=b'),
        );
    }
}
