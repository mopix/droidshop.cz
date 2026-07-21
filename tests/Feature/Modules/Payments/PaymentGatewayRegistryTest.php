<?php

namespace Tests\Feature\Modules\Payments;

use App\Core\Payments\Contracts\PaymentGatewayRegistry;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payments\Services\ComgateGateway;
use Modules\Shipping\Models\PaymentMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class PaymentGatewayRegistryTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'payments');
    }

    private function registry(): PaymentGatewayRegistry
    {
        return app(PaymentGatewayRegistry::class);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function makeComgate(Tenant $tenant, array $settings = ['merchant' => 'M-123', 'secret' => 's3cr3t'], bool $active = true): PaymentMethod
    {
        return $this->context->runAs($tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_COMGATE,
            'name' => 'Platební karta',
            'fee' => 0,
            'is_active' => $active,
            'position' => 1,
            'settings' => $settings,
        ]));
    }

    public function test_a_configured_comgate_method_resolves_a_driver(): void
    {
        $this->makeComgate($this->tenant);

        $gateway = $this->context->runAs($this->tenant, fn () => $this->registry()->for('comgate'));

        $this->assertInstanceOf(ComgateGateway::class, $gateway);
        $this->assertSame('comgate', $gateway->provider());
        $this->assertSame(['comgate'], $this->context->runAs($this->tenant, fn () => $this->registry()->available()));
    }

    public function test_an_unknown_provider_resolves_nothing(): void
    {
        $this->makeComgate($this->tenant);

        $this->assertNull($this->context->runAs($this->tenant, fn () => $this->registry()->for('gopay')));
    }

    public function test_a_comgate_method_without_credentials_does_not_resolve(): void
    {
        $this->makeComgate($this->tenant, ['merchant' => 'M-123', 'secret' => '']);

        $this->assertNull($this->context->runAs($this->tenant, fn () => $this->registry()->for('comgate')));
        $this->assertSame([], $this->context->runAs($this->tenant, fn () => $this->registry()->available()));
    }

    public function test_an_inactive_comgate_method_does_not_resolve(): void
    {
        $this->makeComgate($this->tenant, active: false);

        $this->assertNull($this->context->runAs($this->tenant, fn () => $this->registry()->for('comgate')));
    }

    public function test_a_tenant_without_the_module_resolves_nothing(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->makeComgate($other);
        // module payments not activated for $other

        $this->assertNull($this->context->runAs($other, fn () => $this->registry()->for('comgate')));
        $this->assertSame([], $this->context->runAs($other, fn () => $this->registry()->available()));
    }

    public function test_credentials_never_cross_a_tenant_boundary(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'payments');

        // Only tenant A configures Comgate.
        $this->makeComgate($this->tenant);

        $this->assertNotNull($this->context->runAs($this->tenant, fn () => $this->registry()->for('comgate')));
        // Tenant B, on the same deploy with the module on, sees no method of
        // its own — the BelongsToTenant scope hides A's row entirely.
        $this->assertNull($this->context->runAs($other, fn () => $this->registry()->for('comgate')));
    }
}
