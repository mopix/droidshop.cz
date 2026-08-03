<?php

namespace Tests\Feature\PageCache;

use App\Core\Enums\TenantStatus;
use App\Core\PageCache\PageCachePolicy;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Customers\Models\Customer;
use Tests\TestCase;

class PageCachePolicyTest extends TestCase
{
    use RefreshDatabase;

    private PageCachePolicy $policy;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('pagecache.enabled', true);

        $this->policy = app(PageCachePolicy::class);
        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    private function getRequest(string $uri = '/kategorie/boty'): Request
    {
        return Request::create($uri, 'GET');
    }

    public function test_an_anonymous_get_on_a_running_shop_is_cacheable(): void
    {
        $tenant = Tenant::factory()->create();
        $this->context->set($tenant);

        $this->assertTrue($this->policy->tenantFor($this->getRequest())->is($tenant));
    }

    public function test_a_request_without_a_tenant_is_not_cacheable(): void
    {
        $this->assertNull($this->policy->tenantFor($this->getRequest()));
    }

    public function test_a_post_is_not_cacheable(): void
    {
        $this->context->set(Tenant::factory()->create());

        $this->assertNull($this->policy->tenantFor(Request::create('/kosik', 'POST')));
    }

    public function test_a_signed_in_customer_bypasses_the_cache(): void
    {
        $this->context->set(Tenant::factory()->create());

        // The header renders "Můj účet" instead of "Přihlásit se" for them
        // (shop.blade.php). Storing that would hand one visitor's state to
        // the next anonymous one.
        $this->actingAs(
            Customer::factory()->create(),
            'customer'
        );
        $this->assertNull($this->policy->tenantFor($this->getRequest()));
    }

    public function test_a_suspended_shop_is_not_cacheable(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
        $this->context->set($tenant);

        $this->assertNull($this->policy->tenantFor($this->getRequest()));
    }

    public function test_the_global_switch_turns_everything_off(): void
    {
        config()->set('pagecache.enabled', false);
        $this->context->set(Tenant::factory()->create());

        $this->assertNull($this->policy->tenantFor($this->getRequest()));
    }

    public function test_only_ok_and_gone_responses_may_be_stored(): void
    {
        $ok = new Response('ok', 200);
        $ok->headers->set('Cache-Control', 'public');
        $this->assertTrue($this->policy->mayStore($ok));

        $notFound = new Response('gone', 404);
        $notFound->headers->set('Cache-Control', 'public');
        $this->assertTrue($this->policy->mayStore($notFound));

        $gone = new Response('gone', 410);
        $gone->headers->set('Cache-Control', 'public');
        $this->assertTrue($this->policy->mayStore($gone));

        $serverError = new Response('boom', 500);
        $serverError->headers->set('Cache-Control', 'public');
        $this->assertFalse($this->policy->mayStore($serverError));

        $redirect = new Response('go', 302);
        $redirect->headers->set('Cache-Control', 'public');
        $this->assertFalse($this->policy->mayStore($redirect));
    }

    public function test_a_private_response_is_never_stored(): void
    {
        $private = new Response('cart', 200, ['Cache-Control' => 'private, no-store']);

        $this->assertFalse($this->policy->mayStore($private));
    }

    public function test_a_response_that_sets_a_cookie_is_never_stored(): void
    {
        $response = new Response('ok', 200);
        $response->headers->setCookie(cookie('flash', 'x'));

        $this->assertFalse($this->policy->mayStore($response));
    }
}
