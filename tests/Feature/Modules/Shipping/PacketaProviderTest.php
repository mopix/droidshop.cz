<?php

namespace Tests\Feature\Modules\Shipping;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Shipping\Models\ShippingMethod;
use Tests\TestCase;

class PacketaProviderTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    public function test_a_shipping_method_can_be_stored_with_the_packeta_provider(): void
    {
        $tenant = Tenant::factory()->create();

        $method = $this->context->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 8900,
            'currency' => 'CZK',
        ]));

        $this->assertSame('packeta', $method->fresh()->provider);
    }

    public function test_shipping_option_exposes_its_provider(): void
    {
        $tenant = Tenant::factory()->create();

        $method = $this->context->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PICKUP,
            'name' => 'Osobní odběr',
            'price' => 0,
            'currency' => 'CZK',
        ]));

        $this->assertSame('pickup', $method->provider());
    }
}
