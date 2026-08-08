<?php

namespace Tests\Feature\Tenant;

use App\Core\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class AdminHomeTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');

        // The dashboard reads the module registry, so the modules have to be
        // on record before a tenant can switch one on.
        $this->artisan('modules:sync')->assertSuccessful();
    }

    private function ownerOnHost(): array
    {
        $tenant = Tenant::factory()->create();
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    /**
     * Since wave 3.5 `/admin` is a screen of its own.
     *
     * It used to redirect to whichever module came first in the menu, which
     * meant the owner landed on a product list with no sense of how the shop
     * was doing — and the grouped menu the owner asked for starts with
     * "Nástěnka", which needed somewhere to go.
     */
    public function test_admin_renders_the_dashboard(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();
        $this->activateModule($tenant, 'products');

        $this->actingAs($owner)
            ->get('http://shop.'.config('tenancy.platform_domain').'/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tenant/Dashboard')
                ->has('summary')
                ->has('usage')
                ->where('shop.name', $tenant->name));
    }

    /**
     * A shop running no orders module still gets a dashboard — with zeroes
     * and no dead links — rather than an error. Same guest-safe null-binding
     * rule the rest of the platform follows.
     */
    public function test_a_shop_without_the_orders_module_still_gets_a_dashboard(): void
    {
        [, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->get('http://shop.'.config('tenancy.platform_domain').'/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tenant/Dashboard')
                ->where('summary.placed', 0)
                ->where('summary.revenue', 0)
                ->where('links.orders', null));
    }

    public function test_suspended_tenant_can_still_read_the_admin(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();
        $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

        $response = $this->actingAs($owner)
            ->get('http://shop.'.config('tenancy.platform_domain').'/admin');

        // Anything but a 503 proves read access survives.
        $this->assertNotSame(503, $response->getStatusCode());
    }

    public function test_suspended_tenant_cannot_mutate_the_admin(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();
        $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

        $response = $this->actingAs($owner)
            ->patch('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace', [
                'billing_name' => 'Acme s.r.o.',
            ]);

        $response->assertStatus(503);
    }

    public function test_active_tenant_can_still_mutate_the_admin(): void
    {
        [, $owner] = $this->ownerOnHost();

        $response = $this->actingAs($owner)
            ->patch('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace', [
                'billing_name' => 'Acme s.r.o.',
            ]);

        $this->assertNotSame(503, $response->getStatusCode());
    }
}
