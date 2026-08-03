<?php

namespace Tests\Feature\PageCache;

use App\Core\Enums\TenantStatus;
use App\Core\PageCache\PageCachePolicy;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
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

    public function test_a_signed_in_staff_member_bypasses_the_cache(): void
    {
        $this->context->set(Tenant::factory()->create());

        // Staff browsing their own shop renders affordances (edit links, etc.)
        // that must not be shown to shoppers via cache.
        $this->actingAs(User::factory()->create());
        $this->assertNull($this->policy->tenantFor($this->getRequest()));
    }

    public function test_a_flash_message_in_session_bypasses_the_cache(): void
    {
        $this->context->set(Tenant::factory()->create());
        $request = $this->getRequest();
        $request->setLaravelSession($this->app['session.store']);
        $request->session()->put('status', 'Item added to cart');

        $this->assertNull($this->policy->tenantFor($request));
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
        // Bare 200 response with framework defaults (no-cache, private) is storable.
        $ok = new Response('ok', 200);
        $this->assertTrue($this->policy->mayStore($ok));

        // 404 storable.
        $notFound = new Response('gone', 404);
        $this->assertTrue($this->policy->mayStore($notFound));

        // 410 storable.
        $gone = new Response('gone', 410);
        $this->assertTrue($this->policy->mayStore($gone));

        // 500 not storable.
        $serverError = new Response('boom', 500);
        $this->assertFalse($this->policy->mayStore($serverError));

        // 302 not storable.
        $redirect = new Response('go', 302);
        $this->assertFalse($this->policy->mayStore($redirect));
    }

    public function test_a_response_with_no_store_is_never_stored(): void
    {
        $noStore = new Response('cart', 200, ['Cache-Control' => 'no-store']);

        $this->assertFalse($this->policy->mayStore($noStore));
    }

    public function test_a_response_with_private_and_no_store_is_never_stored(): void
    {
        $privateNoStore = new Response('cart', 200, ['Cache-Control' => 'private, no-store']);

        $this->assertFalse($this->policy->mayStore($privateNoStore));
    }

    public function test_a_response_that_sets_a_cookie_is_never_stored(): void
    {
        $response = new Response('ok', 200);
        $response->headers->setCookie(cookie('flash', 'x'));

        $this->assertFalse($this->policy->mayStore($response));
    }

    public function test_a_real_response_from_helper_is_storable(): void
    {
        // Test that responses created through response() helper (as controllers do)
        // are storable. Framework defaults like "no-cache, private" should not
        // prevent storage via our server-side policy.
        $response = response('Product listing');

        // Real responses from response() helper should be storable.
        $this->assertTrue($this->policy->mayStore($response));
    }
}
