<?php

namespace Tests\Feature\Domains;

use App\Core\Domains\Contracts\DnsChecker;
use App\Core\Domains\DomainVerifier;
use App\Core\Enums\SslStatus;
use App\Core\Tenancy\DomainTenantFinder;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeDnsChecker;
use Tests\TestCase;

/**
 * DomainVerifier is the only authority that sets verified_at (2026-07-22
 * decision precedent, applied to wave 2.1 custom domains). It proves
 * ownership two ways: a DNS TXT challenge token, plus proof the domain
 * actually routes to the platform (CNAME to the edge host, or an A record
 * pointing at the edge server IP for apex domains).
 */
class DomainVerifierTest extends TestCase
{
    use RefreshDatabase;

    private FakeDnsChecker $dns;

    private DomainVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeDnsChecker;
        $this->app->instance(DnsChecker::class, $this->dns);

        $this->verifier = $this->app->make(DomainVerifier::class);
    }

    private function challengeHost(Domain $domain): string
    {
        return config('platform.challenge_prefix').'.'.$domain->domain;
    }

    public function test_cname_path_verifies_ownership(): void
    {
        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'challenge_token' => 'abc123token',
        ]);

        $this->dns->setTxt($this->challengeHost($domain), ['abc123token']);
        $this->dns->setCname($domain->domain, 'tenant-42.'.config('platform.edge_host'));

        $this->verifier->verify($domain);

        $domain->refresh();
        $this->assertNotNull($domain->verified_at);
        $this->assertSame(SslStatus::Pending, $domain->ssl_status);
        $this->assertNull($domain->verification_error);
        $this->assertNotNull($domain->last_checked_at);
    }

    public function test_apex_a_record_path_verifies_ownership(): void
    {
        config()->set('platform.server_ip', '203.0.113.10');

        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('example.cz')->create([
            'challenge_token' => 'abc123token',
        ]);

        $this->dns->setTxt($this->challengeHost($domain), ['abc123token']);
        $this->dns->setA($domain->domain, ['203.0.113.10']);

        $this->verifier->verify($domain);

        $domain->refresh();
        $this->assertNotNull($domain->verified_at);
        $this->assertSame(SslStatus::Pending, $domain->ssl_status);
        $this->assertNull($domain->verification_error);
        $this->assertNotNull($domain->last_checked_at);
    }

    public function test_missing_txt_record_fails_verification(): void
    {
        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'challenge_token' => 'abc123token',
        ]);

        // No TXT configured at all; routing would otherwise be fine.
        $this->dns->setCname($domain->domain, 'tenant-42.'.config('platform.edge_host'));

        $this->verifier->verify($domain);

        $domain->refresh();
        $this->assertNull($domain->verified_at);
        $this->assertSame(SslStatus::Error, $domain->ssl_status);
        $this->assertNotNull($domain->verification_error);
        $this->assertStringContainsStringIgnoringCase('TXT', $domain->verification_error);
        $this->assertNotNull($domain->last_checked_at);
    }

    public function test_wrong_txt_value_fails_verification(): void
    {
        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'challenge_token' => 'abc123token',
        ]);

        $this->dns->setTxt($this->challengeHost($domain), ['someone-elses-token']);
        $this->dns->setCname($domain->domain, 'tenant-42.'.config('platform.edge_host'));

        $this->verifier->verify($domain);

        $domain->refresh();
        $this->assertNull($domain->verified_at);
        $this->assertSame(SslStatus::Error, $domain->ssl_status);
        $this->assertStringContainsStringIgnoringCase('TXT', $domain->verification_error);
    }

    public function test_routing_pointing_elsewhere_fails_verification(): void
    {
        config()->set('platform.server_ip', '203.0.113.10');

        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'challenge_token' => 'abc123token',
        ]);

        $this->dns->setTxt($this->challengeHost($domain), ['abc123token']);
        $this->dns->setCname($domain->domain, 'someone-else.example.net');
        $this->dns->setA($domain->domain, ['198.51.100.5']);

        $this->verifier->verify($domain);

        $domain->refresh();
        $this->assertNull($domain->verified_at);
        $this->assertSame(SslStatus::Error, $domain->ssl_status);
        $this->assertNotNull($domain->verification_error);
        $this->assertStringNotContainsStringIgnoringCase('TXT missing', $domain->verification_error);
        $this->assertNotNull($domain->last_checked_at);
    }

    public function test_repeated_verification_on_still_valid_dns_stays_verified(): void
    {
        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'challenge_token' => 'abc123token',
        ]);

        $this->dns->setTxt($this->challengeHost($domain), ['abc123token']);
        $this->dns->setCname($domain->domain, 'tenant-42.'.config('platform.edge_host'));

        $this->verifier->verify($domain);
        $domain->refresh();
        $firstVerifiedAt = $domain->verified_at;

        $this->travel(1)->hour();

        $this->verifier->verify($domain->fresh());

        $domain->refresh();
        $this->assertNotNull($domain->verified_at);
        $this->assertSame(SslStatus::Pending, $domain->ssl_status);
        $this->assertNull($domain->verification_error);
        $this->assertTrue($domain->verified_at->gte($firstVerifiedAt));
    }

    public function test_successful_verification_forgets_the_tenant_finder_cache_entry(): void
    {
        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'challenge_token' => 'abc123token',
        ]);

        $finder = $this->app->make(DomainTenantFinder::class);
        $finder->find($domain->domain);
        $this->assertTrue(Cache::has('tenancy:domain:'.$domain->domain));

        $this->dns->setTxt($this->challengeHost($domain), ['abc123token']);
        $this->dns->setCname($domain->domain, 'tenant-42.'.config('platform.edge_host'));

        $this->verifier->verify($domain);

        $this->assertFalse(Cache::has('tenancy:domain:'.$domain->domain));
    }

    public function test_subdomain_is_never_dns_verified(): void
    {
        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->create([
            'domain' => 'shop.'.config('tenancy.platform_domain', 'droidshop'),
        ]);

        $this->verifier->verify($domain);

        $domain->refresh();
        $this->assertNull($domain->verified_at);
        $this->assertNull($domain->last_checked_at);
        $this->assertSame(SslStatus::None, $domain->ssl_status);
    }
}
