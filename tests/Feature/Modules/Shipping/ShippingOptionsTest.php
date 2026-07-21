<?php

namespace Tests\Feature\Modules\Shipping;

use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Shipping\Contracts\ShippingOptions;
use App\Core\Shipping\NullPaymentOptions;
use App\Core\Shipping\NullShippingOptions;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ShippingOptionsTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'shipping');
    }

    private function shipping(): ShippingOptions
    {
        return app(ShippingOptions::class);
    }

    private function payments(): PaymentOptions
    {
        return app(PaymentOptions::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeShipping(Tenant $tenant, array $attributes = []): ShippingMethod
    {
        return $this->context->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
            'is_active' => true,
            ...$attributes,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePayment(Tenant $tenant, array $attributes = []): PaymentMethod
    {
        return $this->context->runAs($tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_COD,
            'name' => 'Dobírka',
            'fee' => 0,
            'is_active' => true,
            ...$attributes,
        ]));
    }

    public function test_available_returns_active_methods_and_omits_inactive(): void
    {
        $this->makeShipping($this->tenant, ['name' => 'Aktivní']);
        $this->makeShipping($this->tenant, ['name' => 'Vypnutá', 'is_active' => false]);

        $names = $this->context->runAs($this->tenant, fn () => $this->shipping()->available(1000)->map->name()->all());

        $this->assertSame(['Aktivní'], $names);
    }

    public function test_available_filters_by_cart_weight(): void
    {
        $this->makeShipping($this->tenant, ['name' => 'Lehká', 'max_weight_g' => 500]);
        $this->makeShipping($this->tenant, ['name' => 'Bez limitu', 'max_weight_g' => null]);

        $names = $this->context->runAs($this->tenant, fn () => $this->shipping()->available(1000)->map->name()->all());

        $this->assertSame(['Bez limitu'], $names);
    }

    public function test_available_is_ordered_by_position(): void
    {
        $this->makeShipping($this->tenant, ['name' => 'Druhá', 'position' => 20]);
        $this->makeShipping($this->tenant, ['name' => 'První', 'position' => 10]);

        $names = $this->context->runAs($this->tenant, fn () => $this->shipping()->available(1000)->map->name()->all());

        $this->assertSame(['První', 'Druhá'], $names);
    }

    public function test_a_tenant_without_the_module_gets_empty_collections(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        // shipping module is active for $this->tenant only, not for $other.

        $this->makeShipping($this->tenant);

        $shipping = $this->context->runAs($other, fn () => $this->shipping()->available(1000));
        $payments = $this->context->runAs($other, fn () => $this->payments()->forShipping(1));

        $this->assertTrue($shipping->isEmpty());
        $this->assertTrue($payments->isEmpty());
    }

    public function test_for_shipping_without_matrix_rows_returns_all_active_payments(): void
    {
        $method = $this->makeShipping($this->tenant);
        $this->makePayment($this->tenant, ['name' => 'Dobírka']);
        $this->makePayment($this->tenant, ['name' => 'Převodem', 'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER]);

        $names = $this->context->runAs(
            $this->tenant,
            fn () => $this->payments()->forShipping($method->id)->map->name()->all()
        );

        $this->assertEqualsCanonicalizing(['Dobírka', 'Převodem'], $names);
    }

    public function test_for_shipping_with_matrix_rows_returns_only_linked_payments(): void
    {
        $method = $this->makeShipping($this->tenant);
        $cod = $this->makePayment($this->tenant, ['name' => 'Dobírka']);
        $this->makePayment($this->tenant, ['name' => 'Převodem', 'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER]);

        $this->context->runAs($this->tenant, fn () => $method->paymentMethods()->attach($cod->id, ['tenant_id' => $method->tenant_id]));

        $names = $this->context->runAs(
            $this->tenant,
            fn () => $this->payments()->forShipping($method->id)->map->name()->all()
        );

        $this->assertSame(['Dobírka'], $names);
    }

    public function test_for_shipping_never_returns_an_inactive_linked_payment(): void
    {
        $method = $this->makeShipping($this->tenant);
        $inactive = $this->makePayment($this->tenant, ['name' => 'Vypnutá', 'is_active' => false]);

        $this->context->runAs($this->tenant, fn () => $method->paymentMethods()->attach($inactive->id, ['tenant_id' => $method->tenant_id]));

        $names = $this->context->runAs(
            $this->tenant,
            fn () => $this->payments()->forShipping($method->id)->map->name()->all()
        );

        $this->assertSame([], $names);
    }

    public function test_find_returns_the_option_and_null_across_tenants(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'shipping');

        $shipMethod = $this->makeShipping($this->tenant);
        $payMethod = $this->makePayment($this->tenant);

        $this->context->runAs($this->tenant, function () use ($shipMethod, $payMethod) {
            $this->assertSame($shipMethod->id, $this->shipping()->find($shipMethod->id)?->id());
            $this->assertSame($payMethod->id, $this->payments()->find($payMethod->id)?->id());
        });

        $this->context->runAs($other, function () use ($shipMethod, $payMethod) {
            $this->assertNull($this->shipping()->find($shipMethod->id));
            $this->assertNull($this->payments()->find($payMethod->id));
        });
    }

    public function test_queries_never_cross_a_tenant_boundary(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'shipping');

        $mine = $this->makeShipping($this->tenant, ['name' => 'Moje']);
        $this->makePayment($this->tenant, ['name' => 'Moje platba']);
        $this->makeShipping($other, ['name' => 'Cizí']);
        $this->makePayment($other, ['name' => 'Cizí platba']);

        $shipNames = $this->context->runAs($this->tenant, fn () => $this->shipping()->available(1000)->map->name()->all());
        $payNames = $this->context->runAs($this->tenant, fn () => $this->payments()->forShipping($mine->id)->map->name()->all());

        $this->assertSame(['Moje'], $shipNames);
        $this->assertSame(['Moje platba'], $payNames);
    }

    public function test_the_kernel_null_bindings_answer_empty(): void
    {
        // On a deploy without the shipping module the module provider never
        // registers, so the container keeps the kernel's null bindings. The
        // module provider always loads from disk in these tests, so we assert
        // the null classes directly rather than trying to unregister it.
        $this->assertTrue((new NullShippingOptions)->available(1000)->isEmpty());
        $this->assertNull((new NullShippingOptions)->find(1));
        $this->assertTrue((new NullPaymentOptions)->forShipping(1)->isEmpty());
        $this->assertNull((new NullPaymentOptions)->find(1));
    }
}
