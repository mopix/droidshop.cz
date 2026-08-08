<?php

namespace Tests\Feature\Core;

use App\Core\Tax\VatMode;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VatModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_registered_shop_charges_vat(): void
    {
        app(TenantContext::class)->set(Tenant::factory()->create(['vat_payer' => true]));

        $this->assertTrue(app(VatMode::class)->appliesVat());
    }

    public function test_an_unregistered_shop_does_not(): void
    {
        app(TenantContext::class)->set(Tenant::factory()->create(['vat_payer' => false]));

        $this->assertFalse(app(VatMode::class)->appliesVat());
    }

    /**
     * The platform host and console commands reach this through shared
     * layouts. Throwing there would take out pages that have nothing to do
     * with tax.
     */
    public function test_without_a_tenant_it_answers_no_rather_than_throwing(): void
    {
        app(TenantContext::class)->forget();

        $this->assertFalse(app(VatMode::class)->appliesVat());
    }

    /**
     * Registering for VAT has to take effect on the next page, not after a
     * cache expires: the merchant ticks the box precisely because they are
     * charging tax from now on.
     */
    public function test_switching_registration_takes_effect_at_once(): void
    {
        $tenant = Tenant::factory()->create(['vat_payer' => false]);
        app(TenantContext::class)->set($tenant);

        $this->assertFalse(app(VatMode::class)->appliesVat());

        $tenant->vat_payer = true;
        $tenant->save();
        app(TenantContext::class)->set($tenant->fresh());

        $this->assertTrue(app(VatMode::class)->appliesVat());
    }
}
