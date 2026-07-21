<?php

namespace Tests\Feature\Modules\Shipping;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\TestCase;

class ShippingSchemaTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    public function test_a_shipping_method_is_scoped_to_its_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->context->runAs($a, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
            'is_active' => true,
        ]));

        $seenByB = $this->context->runAs($b, fn () => ShippingMethod::pluck('name')->all());

        $this->assertSame([], $seenByB);
    }

    public function test_price_and_free_from_round_trip_as_money(): void
    {
        $tenant = Tenant::factory()->create();

        $method = $this->context->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
            'free_from' => 150000,
            'is_active' => true,
        ]));

        $this->assertSame(9900, $method->price->amount);
        $this->assertSame(150000, $method->free_from->amount);
    }

    public function test_payment_settings_are_encrypted_at_rest(): void
    {
        $tenant = Tenant::factory()->create();

        $method = $this->context->runAs($tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
            'name' => 'Převodem',
            'fee' => 0,
            'settings' => ['iban' => 'CZ6508000000192000145399'],
            'is_active' => true,
        ]));

        // The cast returns the array…
        $this->assertSame('CZ6508000000192000145399', $method->fresh()->settings['iban']);

        // …but the raw column does not hold the IBAN in the clear.
        $raw = \DB::table('payment_methods')->where('id', $method->id)->value('settings');
        $this->assertStringNotContainsString('CZ6508000000192000145399', (string) $raw);
    }
}
