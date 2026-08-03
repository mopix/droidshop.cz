<?php

namespace Tests\Feature\PageCache;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PageCacheMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('pagecache.enabled', true);

        // A route of our own keeps the test about the middleware rather than
        // about whatever the catalogue happens to render today.
        Route::middleware(['web', 'page-cache:catalog'])->get('/pc-probe', function () {
            return response('<p>rendered '.now()->format('u').'</p><input value="'.csrf_token().'">');
        });
    }

    private function shop(string $subdomain = 'obchod'): Tenant
    {
        return Tenant::factory()->withDomain($subdomain.'.droidshop')->create();
    }

    public function test_the_second_request_is_served_from_the_cache(): void
    {
        $tenant = $this->shop();

        $first = $this->get('http://obchod.droidshop/pc-probe')->assertOk()->getContent();
        $second = $this->get('http://obchod.droidshop/pc-probe')->assertOk()->getContent();

        $this->assertSame(
            strip_tags(explode('<input', $first)[0]),
            strip_tags(explode('<input', $second)[0]),
        );
    }

    public function test_a_cache_hit_runs_no_catalogue_queries(): void
    {
        $this->shop();
        $this->get('http://obchod.droidshop/pc-probe')->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get('http://obchod.droidshop/pc-probe')->assertOk();

        // Resolving the tenant still costs a query or two; rendering must not.
        $this->assertLessThanOrEqual(3, $queries);
    }

    public function test_each_visitor_gets_their_own_csrf_token(): void
    {
        $this->shop();

        $first = $this->get('http://obchod.droidshop/pc-probe')->getContent();
        $firstToken = $this->tokenIn($first);

        $this->flushSession();

        $second = $this->get('http://obchod.droidshop/pc-probe')->getContent();
        $secondToken = $this->tokenIn($second);

        $this->assertNotSame('', $firstToken);
        $this->assertNotSame('', $secondToken);
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertStringNotContainsString('@@PAGECACHE_CSRF@@', $second);
    }

    public function test_one_shop_never_receives_another_shops_page(): void
    {
        $this->shop('prvni');
        $this->shop('druhy');

        $first = $this->get('http://prvni.droidshop/pc-probe')->getContent();
        $second = $this->get('http://druhy.droidshop/pc-probe')->getContent();

        $this->assertNotSame(
            strip_tags(explode('<input', $first)[0]),
            strip_tags(explode('<input', $second)[0]),
        );
    }

    public function test_bumping_the_generation_re_renders(): void
    {
        $tenant = $this->shop();

        $before = $this->get('http://obchod.droidshop/pc-probe')->getContent();

        app(Generations::class)->bump($tenant->fresh(), Dimension::Catalog);

        $after = $this->get('http://obchod.droidshop/pc-probe')->getContent();

        $this->assertNotSame(
            strip_tags(explode('<input', $before)[0]),
            strip_tags(explode('<input', $after)[0]),
        );
    }

    public function test_bumping_an_unrelated_dimension_keeps_the_page(): void
    {
        $tenant = $this->shop();

        $before = $this->get('http://obchod.droidshop/pc-probe')->getContent();

        app(Generations::class)->bump($tenant->fresh(), Dimension::Theme);

        $after = $this->get('http://obchod.droidshop/pc-probe')->getContent();

        $this->assertSame(
            strip_tags(explode('<input', $before)[0]),
            strip_tags(explode('<input', $after)[0]),
        );
    }

    public function test_the_response_never_carries_a_stored_cookie(): void
    {
        $this->shop();

        $this->get('http://obchod.droidshop/pc-probe');
        $response = $this->get('http://obchod.droidshop/pc-probe');

        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertNotSame('flash', $cookie->getName());
        }

        $response->assertOk();
    }

    public function test_disabling_the_cache_renders_every_time(): void
    {
        config()->set('pagecache.enabled', false);
        $this->shop();

        $first = $this->get('http://obchod.droidshop/pc-probe')->getContent();
        $second = $this->get('http://obchod.droidshop/pc-probe')->getContent();

        $this->assertNotSame(
            strip_tags(explode('<input', $first)[0]),
            strip_tags(explode('<input', $second)[0]),
        );
    }

    private function tokenIn(string $html): string
    {
        preg_match('/<input value="([^"]*)"/', $html, $matches);

        return $matches[1] ?? '';
    }
}
