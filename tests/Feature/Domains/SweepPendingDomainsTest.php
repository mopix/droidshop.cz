<?php

namespace Tests\Feature\Domains;

use App\Core\Domains\Contracts\DnsChecker;
use App\Core\Enums\SslStatus;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDnsChecker;
use Tests\TestCase;

/**
 * domains:sweep-pending (wave 2.1, task 8) periodically drives custom domains
 * through their lifecycle without a human clicking "check now": unverified ->
 * verify (DNS may have propagated since), verified-but-uncertified -> probe,
 * long-unverified -> give up with a clear error.
 */
class SweepPendingDomainsTest extends TestCase
{
    use RefreshDatabase;

    private FakeDnsChecker $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeDnsChecker;
        $this->app->instance(DnsChecker::class, $this->dns);
    }

    private function challengeHost(Domain $domain): string
    {
        return config('platform.challenge_prefix').'.'.$domain->domain;
    }

    public function test_fresh_pending_domain_with_dns_now_set_gets_verified(): void
    {
        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'challenge_token' => 'abc123token',
            'verified_at' => null,
            'last_checked_at' => null,
        ]);

        $this->dns->setTxt($this->challengeHost($domain), ['abc123token']);
        $this->dns->setCname($domain->domain, 'tenant-42.'.config('platform.edge_host'));

        $this->artisan('domains:sweep-pending')->assertSuccessful();

        $fresh = $domain->fresh();
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame(SslStatus::Pending, $fresh->ssl_status);
    }

    public function test_verified_domain_without_cert_gets_probed(): void
    {
        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'challenge_token' => 'abc123token',
            'verified_at' => now()->subHour(),
            'ssl_status' => SslStatus::Pending,
            'last_checked_at' => now()->subHour(),
        ]);

        Http::fake([
            'https://shop.example.cz/up' => Http::response('', 200),
        ]);

        $this->artisan('domains:sweep-pending')->assertSuccessful();

        $this->assertSame(SslStatus::Issued, $domain->fresh()->ssl_status);
    }

    public function test_expired_pending_domain_with_dns_still_missing_gets_marked_error(): void
    {
        $ttlHours = (int) config('platform.pending_ttl_hours');

        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'challenge_token' => 'abc123token',
            'verified_at' => null,
            'last_checked_at' => null,
            'created_at' => now()->subHours($ttlHours + 1),
        ]);

        // DNS still not configured at all.
        $this->artisan('domains:sweep-pending')->assertSuccessful();

        $fresh = $domain->fresh();
        $this->assertNull($fresh->verified_at);
        $this->assertSame(SslStatus::Error, $fresh->ssl_status);
        $this->assertNotNull($fresh->verification_error);
        $this->assertStringContainsStringIgnoringCase('DNS', $fresh->verification_error);
        $this->assertNotNull($fresh->last_checked_at);
    }

    public function test_recently_checked_pending_domain_is_skipped_by_backoff(): void
    {
        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'challenge_token' => 'abc123token',
            'verified_at' => null,
            'last_checked_at' => now(),
            'ssl_status' => SslStatus::Error,
            'verification_error' => 'previous failure',
        ]);

        // DNS is actually fine now, but backoff should prevent the recheck.
        $this->dns->setTxt($this->challengeHost($domain), ['abc123token']);
        $this->dns->setCname($domain->domain, 'tenant-42.'.config('platform.edge_host'));

        $lastCheckedBefore = $domain->last_checked_at;

        $this->artisan('domains:sweep-pending')->assertSuccessful();

        $fresh = $domain->fresh();
        $this->assertNull($fresh->verified_at);
        $this->assertSame(SslStatus::Error, $fresh->ssl_status);
        $this->assertTrue($fresh->last_checked_at->equalTo($lastCheckedBefore));
    }

    public function test_domain_with_verification_error_auto_retries_once_backoff_elapsed(): void
    {
        $backoff = (int) config('platform.dns_backoff_minutes');

        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'challenge_token' => 'abc123token',
            'verified_at' => null,
            'ssl_status' => SslStatus::Error,
            'verification_error' => 'previous failure',
            'last_checked_at' => now()->subMinutes($backoff + 5),
            'created_at' => now()->subHour(),
        ]);

        $this->dns->setTxt($this->challengeHost($domain), ['abc123token']);
        $this->dns->setCname($domain->domain, 'tenant-42.'.config('platform.edge_host'));

        $this->artisan('domains:sweep-pending')->assertSuccessful();

        $fresh = $domain->fresh();
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame(SslStatus::Pending, $fresh->ssl_status);
        $this->assertNull($fresh->verification_error);
    }

    public function test_terminal_cert_error_is_never_reprobed(): void
    {
        $tenant = Tenant::factory()->create();
        $domain = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'challenge_token' => 'abc123token',
            'verified_at' => now()->subDay(),
            'ssl_status' => SslStatus::Error,
            'verification_error' => 'Certifikát nebyl vydán v očekávaném čase.',
            'last_checked_at' => now()->subDay(),
        ]);

        Http::fake([
            'https://shop.example.cz/up' => Http::response('', 200),
        ]);

        $this->artisan('domains:sweep-pending')->assertSuccessful();

        Http::assertNothingSent();

        $fresh = $domain->fresh();
        $this->assertSame(SslStatus::Error, $fresh->ssl_status);
    }
}
