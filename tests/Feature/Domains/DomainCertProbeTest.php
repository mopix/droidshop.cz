<?php

namespace Tests\Feature\Domains;

use App\Core\Domains\DomainCertProbe;
use App\Core\Domains\Jobs\ProbeDomainCertJob;
use App\Core\Enums\SslStatus;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DomainCertProbe detects that Caddy's on-demand TLS actually issued a
 * certificate for a verified custom domain (wave 2.1, task 6). Verification
 * (task 3) only proves ownership and moves ssl_status to pending — the
 * certificate itself is issued lazily by the edge on first HTTPS hit, so
 * something has to come back and confirm it happened.
 *
 * Scope: this only flips ssl_status pending -> issued|error. The canonical
 * host swap (custom domain becomes the primary/canonical host once its cert
 * is live) is task 7 and is deliberately not touched here.
 */
class DomainCertProbeTest extends TestCase
{
    use RefreshDatabase;

    private DomainCertProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->probe = $this->app->make(DomainCertProbe::class);
    }

    private function verifiedDomain(array $overrides = []): Domain
    {
        $tenant = Tenant::factory()->create();

        return Domain::factory()->for($tenant)->custom('shop.example.cz')->create(array_merge([
            'challenge_token' => 'abc123token',
            'verified_at' => now(),
            'ssl_status' => SslStatus::Pending,
        ], $overrides));
    }

    public function test_successful_probe_marks_the_domain_issued(): void
    {
        $domain = $this->verifiedDomain();

        Http::fake([
            'https://shop.example.cz/up' => Http::response('', 200),
        ]);

        $this->probe->probe($domain);

        $domain->refresh();
        $this->assertSame(SslStatus::Issued, $domain->ssl_status);
        $this->assertNull($domain->verification_error);
        $this->assertNotNull($domain->last_checked_at);
    }

    public function test_failed_probe_below_max_attempts_stays_pending_and_schedules_retry(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake([ProbeDomainCertJob::class]);

        $domain = $this->verifiedDomain();

        Http::fake([
            'https://shop.example.cz/up' => Http::response('', 503),
        ]);

        $this->probe->probe($domain, attempt: 1);

        $domain->refresh();
        $this->assertSame(SslStatus::Pending, $domain->ssl_status);
        $this->assertNull($domain->verification_error);
        $this->assertNotNull($domain->last_checked_at);

        Queue::assertPushed(ProbeDomainCertJob::class, function (ProbeDomainCertJob $job) use ($domain) {
            return $job->domainId() === $domain->id && $job->attempt() === 2;
        });
    }

    public function test_failed_probe_on_sync_queue_does_not_schedule_a_retry(): void
    {
        // phpunit.xml pins QUEUE_CONNECTION=sync; a delayed dispatch there
        // would run immediately and hammer the edge in a tight loop.
        Queue::fake([ProbeDomainCertJob::class]);

        $domain = $this->verifiedDomain();

        Http::fake([
            'https://shop.example.cz/up' => Http::response('', 503),
        ]);

        $this->probe->probe($domain, attempt: 1);

        $domain->refresh();
        $this->assertSame(SslStatus::Pending, $domain->ssl_status);

        Queue::assertNotPushed(ProbeDomainCertJob::class);
    }

    public function test_connection_exception_counts_as_a_failure_not_a_throw(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake([ProbeDomainCertJob::class]);

        $domain = $this->verifiedDomain();

        Http::fake([
            'https://shop.example.cz/up' => fn () => throw new ConnectionException('refused'),
        ]);

        $this->probe->probe($domain, attempt: 1);

        $domain->refresh();
        $this->assertSame(SslStatus::Pending, $domain->ssl_status);
        Queue::assertPushed(ProbeDomainCertJob::class);
    }

    public function test_exhausted_attempts_mark_the_domain_error(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake([ProbeDomainCertJob::class]);

        $maxAttempts = config('platform.cert_probe_max_attempts');
        $domain = $this->verifiedDomain();

        Http::fake([
            'https://shop.example.cz/up' => Http::response('', 503),
        ]);

        $this->probe->probe($domain, attempt: $maxAttempts);

        $domain->refresh();
        $this->assertSame(SslStatus::Error, $domain->ssl_status);
        $this->assertNotNull($domain->verification_error);
        $this->assertNotNull($domain->last_checked_at);

        Queue::assertNotPushed(ProbeDomainCertJob::class);
    }

    public function test_probe_is_a_no_op_on_an_unverified_domain(): void
    {
        $domain = $this->verifiedDomain([
            'verified_at' => null,
            'ssl_status' => SslStatus::Pending,
        ]);

        Http::fake([
            'https://shop.example.cz/up' => Http::response('', 200),
        ]);

        $this->probe->probe($domain);

        $domain->refresh();
        $this->assertSame(SslStatus::Pending, $domain->ssl_status);
        $this->assertNull($domain->last_checked_at);
        Http::assertNothingSent();
    }

    public function test_probe_is_a_no_op_once_already_issued(): void
    {
        $domain = $this->verifiedDomain([
            'ssl_status' => SslStatus::Issued,
        ]);

        Http::fake([
            'https://shop.example.cz/up' => Http::response('', 500),
        ]);

        $this->probe->probe($domain);

        $domain->refresh();
        $this->assertSame(SslStatus::Issued, $domain->ssl_status);
        Http::assertNothingSent();
    }
}
