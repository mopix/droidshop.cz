<?php

namespace Tests\Feature\Tenant;

use App\Core\Domains\Contracts\DnsChecker;
use App\Core\Enums\DomainType;
use App\Core\Enums\SslStatus;
use App\Core\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeDnsChecker;
use Tests\TestCase;

class DomainControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function ownerOnHost(array $tenantAttributes = [], string $subdomain = 'shop'): array
    {
        $tenant = Tenant::factory()->create($tenantAttributes);
        Domain::create([
            'tenant_id' => $tenant->id,
            'domain' => $subdomain.'.'.config('tenancy.platform_domain'),
            'type' => 'subdomain',
            'is_primary' => true,
        ]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    private function host(): string
    {
        return 'http://shop.'.config('tenancy.platform_domain');
    }

    public function test_owner_can_view_the_domain_screen(): void
    {
        [, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->get($this->host().'/admin/nastaveni/domena')
            ->assertInertia(fn (Assert $p) => $p->component('Tenant/Domain')
                ->where('custom', null)
                ->where('instructions', null)
            );
    }

    public function test_owner_can_add_a_custom_domain(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/domena', ['domain' => 'muj-eshop.cz'])
            ->assertRedirect();

        $custom = Domain::where('tenant_id', $tenant->id)->where('type', DomainType::Custom)->first();

        $this->assertNotNull($custom);
        $this->assertSame('muj-eshop.cz', $custom->domain);
        $this->assertFalse($custom->is_primary);
        $this->assertSame(SslStatus::None, $custom->ssl_status);
        $this->assertNull($custom->verified_at);
        $this->assertNotNull($custom->challenge_token);
        $this->assertSame(40, strlen($custom->challenge_token));
    }

    public function test_store_rejects_a_platform_subdomain(): void
    {
        [, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/domena', [
                'domain' => 'jiny.'.config('tenancy.platform_domain'),
            ])
            ->assertSessionHasErrors('domain');
    }

    public function test_store_rejects_a_duplicate_domain(): void
    {
        [, $owner] = $this->ownerOnHost();
        [$other] = $this->ownerOnHost(subdomain: 'other-shop');
        Domain::factory()->for($other, 'tenant')->custom('taken.cz')->create();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/domena', ['domain' => 'taken.cz'])
            ->assertSessionHasErrors('domain');
    }

    public function test_store_rejects_a_second_custom_domain_for_the_same_tenant(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();
        Domain::factory()->for($tenant, 'tenant')->custom('first.cz')->create();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/domena', ['domain' => 'second.cz'])
            ->assertSessionHasErrors('domain');

        $this->assertSame(1, Domain::where('tenant_id', $tenant->id)->where('type', DomainType::Custom)->count());
    }

    public function test_store_rejects_an_invalid_fqdn(): void
    {
        [, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/domena', ['domain' => 'not a domain'])
            ->assertSessionHasErrors('domain');
    }

    public function test_verify_checks_dns_for_an_unverified_domain(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();
        $custom = Domain::factory()->for($tenant, 'tenant')->custom('muj-eshop.cz')->create([
            'challenge_token' => 'abc123',
            'verified_at' => null,
            'ssl_status' => SslStatus::None,
        ]);

        $dns = $this->app->make(FakeDnsChecker::class);
        $dns->setTxt(config('platform.challenge_prefix').'.muj-eshop.cz', ['abc123']);
        $dns->setCname('muj-eshop.cz', config('platform.edge_host'));
        $this->app->instance(DnsChecker::class, $dns);

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/domena/overit')
            ->assertRedirect();

        $custom->refresh();
        $this->assertNotNull($custom->verified_at);
        $this->assertSame(SslStatus::Pending, $custom->ssl_status);
    }

    public function test_verify_resets_and_re_probes_a_verified_domain_stuck_in_error(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();
        $custom = Domain::factory()->for($tenant, 'tenant')->custom('muj-eshop.cz')->create([
            'verified_at' => now(),
            'ssl_status' => SslStatus::Error,
            'verification_error' => 'Certifikát nebyl vydán v očekávaném čase.',
        ]);

        Http::fake(['https://muj-eshop.cz/up' => Http::response('ok', 200)]);

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/domena/overit')
            ->assertRedirect();

        $custom->refresh();
        $this->assertSame(SslStatus::Issued, $custom->ssl_status);
        $this->assertNull($custom->verification_error);
    }

    public function test_owner_can_remove_the_custom_domain_and_restores_the_subdomain_as_primary(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();
        Domain::where('tenant_id', $tenant->id)->where('type', 'subdomain')->update(['is_primary' => false]);
        $custom = Domain::factory()->for($tenant, 'tenant')->custom('muj-eshop.cz')->create([
            'is_primary' => true,
            'ssl_status' => SslStatus::Issued,
            'verified_at' => now(),
        ]);

        $this->actingAs($owner)
            ->delete($this->host().'/admin/nastaveni/domena')
            ->assertRedirect();

        $this->assertNull(Domain::find($custom->id));
        $subdomain = Domain::where('tenant_id', $tenant->id)->where('type', 'subdomain')->first();
        $this->assertTrue($subdomain->is_primary);
    }

    public function test_guest_cannot_access(): void
    {
        $this->ownerOnHost();

        $this->get($this->host().'/admin/nastaveni/domena')
            ->assertRedirect(); // tenant.member throws AuthenticationException -> login redirect
    }

    public function test_non_member_cannot_access(): void
    {
        $this->ownerOnHost();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get($this->host().'/admin/nastaveni/domena')
            ->assertForbidden();
    }

    public function test_suspended_tenant_cannot_write(): void
    {
        [, $owner] = $this->ownerOnHost(['status' => TenantStatus::Suspended]);

        $this->actingAs($owner)
            ->post($this->host().'/admin/nastaveni/domena', ['domain' => 'muj-eshop.cz'])
            ->assertStatus(503);
    }
}
