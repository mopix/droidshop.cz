<?php

namespace Tests\Feature\Domains;

use App\Core\Domains\CanonicalDomain;
use App\Core\Enums\DomainType;
use App\Core\Enums\SslStatus;
use App\Models\AuditLogEntry;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * CanonicalDomain owns the swap from "subdomain is primary" to "custom
 * domain is primary" once its certificate is live (wave 2.1, task 7). Only
 * one domain per tenant may carry is_primary — the storefront, docs, mail
 * headers and now the 301 redirect all read that single flag.
 */
class CanonicalDomainTest extends TestCase
{
    use RefreshDatabase;

    private CanonicalDomain $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(CanonicalDomain::class);
    }

    public function test_promote_makes_the_custom_domain_primary_and_demotes_the_subdomain(): void
    {
        $tenant = Tenant::factory()->create();
        $subdomain = Domain::factory()->for($tenant)->create([
            'domain' => 'shop.'.config('tenancy.platform_domain', 'droidshop'),
            'is_primary' => true,
        ]);
        $custom = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'is_primary' => false,
            'ssl_status' => SslStatus::Issued,
        ]);

        $this->service->promote($custom);

        $this->assertTrue($custom->fresh()->is_primary);
        $this->assertFalse($subdomain->fresh()->is_primary);
    }

    public function test_promote_is_a_no_op_on_an_unissued_custom_domain(): void
    {
        $tenant = Tenant::factory()->create();
        $subdomain = Domain::factory()->for($tenant)->create([
            'domain' => 'shop.'.config('tenancy.platform_domain', 'droidshop'),
            'is_primary' => true,
        ]);
        $custom = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'is_primary' => false,
            'ssl_status' => SslStatus::Pending,
        ]);

        $this->service->promote($custom);

        $this->assertFalse($custom->fresh()->is_primary);
        $this->assertTrue($subdomain->fresh()->is_primary);
    }

    public function test_promote_is_a_no_op_on_a_subdomain(): void
    {
        $tenant = Tenant::factory()->create();
        $subdomain = Domain::factory()->for($tenant)->create([
            'domain' => 'shop.'.config('tenancy.platform_domain', 'droidshop'),
            'is_primary' => true,
            'type' => DomainType::Subdomain,
            'ssl_status' => SslStatus::Issued,
        ]);

        $this->service->promote($subdomain);

        $this->assertTrue($subdomain->fresh()->is_primary);
    }

    public function test_promote_leaves_exactly_one_primary_domain_across_multiple_others(): void
    {
        $tenant = Tenant::factory()->create();
        $subdomain = Domain::factory()->for($tenant)->create([
            'domain' => 'shop.'.config('tenancy.platform_domain', 'droidshop'),
            'is_primary' => true,
        ]);
        $otherCustom = Domain::factory()->for($tenant)->custom('old.example.cz')->create([
            'is_primary' => false,
            'ssl_status' => SslStatus::Error,
        ]);
        $custom = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'is_primary' => false,
            'ssl_status' => SslStatus::Issued,
        ]);

        $this->service->promote($custom);

        $this->assertSame(1, Domain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->count());
        $this->assertTrue($custom->fresh()->is_primary);
        $this->assertFalse($subdomain->fresh()->is_primary);
        $this->assertFalse($otherCustom->fresh()->is_primary);
    }

    public function test_promote_is_idempotent_when_already_primary(): void
    {
        $tenant = Tenant::factory()->create();
        $subdomain = Domain::factory()->for($tenant)->create([
            'domain' => 'shop.'.config('tenancy.platform_domain', 'droidshop'),
            'is_primary' => true,
        ]);
        $custom = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'is_primary' => false,
            'ssl_status' => SslStatus::Issued,
        ]);

        $this->service->promote($custom);
        $this->service->promote($custom->fresh());

        $this->assertSame(1, Domain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->count());
        $this->assertTrue($custom->fresh()->is_primary);
        $this->assertFalse($subdomain->fresh()->is_primary);

        // A repeat call once the custom domain is the tenant's one and only
        // primary is a true no-op: no second audit entry pretending a
        // promotion happened again.
        $this->assertSame(1, AuditLogEntry::where('action', 'domain.promoted')->count());
    }

    public function test_promote_forgets_the_finder_cache_for_the_custom_and_previous_primary_host(): void
    {
        $tenant = Tenant::factory()->create();
        $subdomain = Domain::factory()->for($tenant)->create([
            'domain' => 'shop.'.config('tenancy.platform_domain', 'droidshop'),
            'is_primary' => true,
        ]);
        $custom = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'is_primary' => false,
            'ssl_status' => SslStatus::Issued,
        ]);

        Cache::put('tenancy:domain:'.$subdomain->domain, $tenant->id, 300);
        Cache::put('tenancy:domain:'.$custom->domain, null, 300);

        $this->service->promote($custom);

        $this->assertFalse(Cache::has('tenancy:domain:'.$subdomain->domain));
        $this->assertFalse(Cache::has('tenancy:domain:'.$custom->domain));
    }

    public function test_promote_writes_an_audit_entry(): void
    {
        $tenant = Tenant::factory()->create();
        Domain::factory()->for($tenant)->create([
            'domain' => 'shop.'.config('tenancy.platform_domain', 'droidshop'),
            'is_primary' => true,
        ]);
        $custom = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'is_primary' => false,
            'ssl_status' => SslStatus::Issued,
        ]);

        $this->service->promote($custom);

        $entry = AuditLogEntry::where('action', 'domain.promoted')->firstOrFail();
        $this->assertSame($tenant->id, $entry->tenant_id);
        $this->assertSame($custom->id, (int) $entry->subject_id);
    }

    public function test_canonical_host_for_returns_the_primary_domain_host(): void
    {
        $tenant = Tenant::factory()->create();
        Domain::factory()->for($tenant)->create([
            'domain' => 'shop.'.config('tenancy.platform_domain', 'droidshop'),
            'is_primary' => false,
        ]);
        $custom = Domain::factory()->for($tenant)->custom('shop.example.cz')->create([
            'is_primary' => true,
            'ssl_status' => SslStatus::Issued,
        ]);

        $this->assertSame($custom->domain, $this->service->canonicalHostFor($tenant));
    }

    public function test_canonical_host_for_returns_null_when_tenant_has_no_primary_domain(): void
    {
        $tenant = Tenant::factory()->create();
        Domain::factory()->for($tenant)->create([
            'domain' => 'shop.'.config('tenancy.platform_domain', 'droidshop'),
            'is_primary' => false,
        ]);

        $this->assertNull($this->service->canonicalHostFor($tenant));
    }
}
