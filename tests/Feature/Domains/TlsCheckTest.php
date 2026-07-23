<?php

namespace Tests\Feature\Domains;

use App\Core\Enums\DomainType;
use App\Core\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Caddy's on-demand TLS "ask" endpoint (wave 2.1). This is the sole gate
 * between "something points its DNS at us" and "we ask Let's Encrypt for a
 * certificate" — it must say yes only for a verified custom domain of a
 * tenant whose storefront is still allowed to answer, and must never be
 * reachable from outside the edge box itself.
 */
class TlsCheckTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-shared-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('platform.tls_check_token', self::TOKEN);
    }

    /**
     * Appends the shared-secret token every passing test needs, the way
     * Caddy's configured ask URL would.
     */
    private function url(string $path): string
    {
        return $path.(str_contains($path, '?') ? '&' : '?').'token='.self::TOKEN;
    }

    public function test_verified_custom_domain_of_active_tenant_is_allowed(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get($this->url('/internal/tls-check?domain=shop.example.cz'))->assertOk();
    }

    public function test_verified_custom_domain_of_trial_tenant_is_allowed(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Trial]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get($this->url('/internal/tls-check?domain=shop.example.cz'))->assertOk();
    }

    public function test_verified_custom_domain_of_past_due_tenant_is_allowed(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::PastDue]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get($this->url('/internal/tls-check?domain=shop.example.cz'))->assertOk();
    }

    public function test_unverified_custom_domain_is_denied(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => null,
        ]);

        $this->get($this->url('/internal/tls-check?domain=shop.example.cz'))->assertNotFound();
    }

    public function test_unknown_domain_is_denied(): void
    {
        $this->get($this->url('/internal/tls-check?domain=nowhere.example.cz'))->assertNotFound();
    }

    public function test_suspended_tenant_is_denied(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get($this->url('/internal/tls-check?domain=shop.example.cz'))->assertNotFound();
    }

    public function test_pending_deletion_tenant_is_denied(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::PendingDeletion]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get($this->url('/internal/tls-check?domain=shop.example.cz'))->assertNotFound();
    }

    public function test_deleted_tenant_is_denied(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Deleted]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get($this->url('/internal/tls-check?domain=shop.example.cz'))->assertNotFound();
    }

    public function test_subdomain_is_denied_even_though_it_resolves_fine(): void
    {
        // Subdomains ride the wildcard certificate; on-demand TLS must never
        // be asked to issue for one, verified or not.
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        $host = 'shop.'.config('tenancy.platform_domain', 'droidshop');
        Domain::factory()->for($tenant)->create([
            'domain' => $host,
            'type' => DomainType::Subdomain,
        ]);

        $this->get($this->url('/internal/tls-check?domain='.$host))->assertNotFound();
    }

    public function test_missing_domain_query_param_is_denied(): void
    {
        $this->get($this->url('/internal/tls-check'))->assertNotFound();
    }

    public function test_domain_query_param_is_matched_case_insensitively(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get($this->url('/internal/tls-check?domain=SHOP.EXAMPLE.CZ'))->assertOk();
    }

    public function test_request_from_non_localhost_ip_is_denied(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get($this->url('/internal/tls-check?domain=shop.example.cz'), [
            'REMOTE_ADDR' => '203.0.113.5',
        ])->assertNotFound();
    }

    public function test_request_from_ipv4_loopback_is_allowed_through_the_local_guard(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get($this->url('/internal/tls-check?domain=shop.example.cz'), [
            'REMOTE_ADDR' => '127.0.0.1',
        ])->assertOk();
    }

    public function test_request_from_ipv6_loopback_is_allowed_through_the_local_guard(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get($this->url('/internal/tls-check?domain=shop.example.cz'), [
            'REMOTE_ADDR' => '::1',
        ])->assertOk();
    }

    public function test_result_is_cached_so_a_repeat_ask_costs_no_extra_query(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get($this->url('/internal/tls-check?domain=shop.example.cz'))->assertOk();

        DB::enableQueryLog();
        $this->get($this->url('/internal/tls-check?domain=shop.example.cz'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries);
    }

    public function test_missing_token_is_denied_even_for_an_otherwise_valid_domain(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get('/internal/tls-check?domain=shop.example.cz')->assertNotFound();
    }

    public function test_wrong_token_is_denied_even_for_an_otherwise_valid_domain(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get('/internal/tls-check?domain=shop.example.cz&token=wrong-secret')->assertNotFound();
    }

    public function test_unconfigured_token_denies_every_request(): void
    {
        config()->set('platform.tls_check_token', null);

        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'verified_at' => now(),
        ]);

        $this->get('/internal/tls-check?domain=shop.example.cz&token='.self::TOKEN)->assertNotFound();
    }
}
