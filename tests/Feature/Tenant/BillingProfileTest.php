<?php

namespace Tests\Feature\Tenant;

use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingProfileTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOnHost(): array
    {
        $tenant = Tenant::factory()->create();
        Domain::create(['tenant_id' => $tenant->id, 'domain' => 'shop.'.config('tenancy.platform_domain'), 'type' => 'subdomain', 'is_primary' => true]);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    public function test_owner_can_view_billing_profile(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->get('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace')
            ->assertInertia(fn (Assert $p) => $p->component('Tenant/BillingProfile'));
    }

    public function test_owner_can_update_billing_profile(): void
    {
        [$tenant, $owner] = $this->ownerOnHost();

        $this->actingAs($owner)
            ->patch('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace', [
                'billing_name' => 'Nájemce s.r.o.',
                'billing_ico' => '12345678',
                'billing_dic' => 'CZ12345678',
                'vat_payer' => true,
                'billing_address' => ['street' => 'Ulice 1', 'city' => 'Praha', 'zip' => '11000'],
            ])->assertRedirect();

        $this->assertSame('Nájemce s.r.o.', $tenant->fresh()->billing_name);
    }

    public function test_guest_cannot_access(): void
    {
        $this->ownerOnHost();
        $this->get('http://shop.'.config('tenancy.platform_domain').'/admin/nastaveni/fakturace')
            ->assertRedirect(); // tenant.member throws AuthenticationException -> login redirect
    }
}
