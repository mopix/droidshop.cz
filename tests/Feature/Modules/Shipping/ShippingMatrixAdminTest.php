<?php

namespace Tests\Feature\Modules\Shipping;

use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ShippingMatrixAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'shipping');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(): string
    {
        return 'http://shop1.droidshop/admin/m/shipping/matice';
    }

    private function shipping(Tenant $tenant, array $attributes = []): ShippingMethod
    {
        return $this->context->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
            'is_active' => true,
            ...$attributes,
        ]));
    }

    private function payment(Tenant $tenant, array $attributes = []): PaymentMethod
    {
        return $this->context->runAs($tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_COD,
            'name' => 'Dobírka',
            'fee' => 0,
            'is_active' => true,
            ...$attributes,
        ]));
    }

    public function test_the_grid_renders_active_methods_of_both_kinds(): void
    {
        $this->shipping($this->tenant, ['name' => 'Kurýr']);
        $this->shipping($this->tenant, ['name' => 'Vypnutá', 'is_active' => false]);
        $this->payment($this->tenant, ['name' => 'Dobírka']);
        $this->payment($this->tenant, ['name' => 'Vypnutá platba', 'is_active' => false]);

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Shipping/Matrix')
                ->has('shippingMethods', 1)
                ->has('paymentMethods', 1)
                ->where('shippingMethods.0.name', 'Kurýr')
                ->where('paymentMethods.0.name', 'Dobírka')
            );
    }

    public function test_ticking_creates_a_pair_and_unticking_removes_it(): void
    {
        $ship = $this->shipping($this->tenant);
        $cod = $this->payment($this->tenant, ['name' => 'Dobírka']);
        $bank = $this->payment($this->tenant, ['name' => 'Převodem', 'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER]);

        // Tick cod only for this shipping method.
        $this->actingAs($this->owner)
            ->put($this->url(), ['matrix' => [$ship->id => [$cod->id]]])
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('shipping_method_payment_method', [
            'shipping_method_id' => $ship->id, 'payment_method_id' => $cod->id,
        ]));
        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseMissing('shipping_method_payment_method', [
            'shipping_method_id' => $ship->id, 'payment_method_id' => $bank->id,
        ]));

        // Untick everything for this shipping method.
        $this->actingAs($this->owner)
            ->put($this->url(), ['matrix' => [$ship->id => []]])
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseMissing('shipping_method_payment_method', [
            'shipping_method_id' => $ship->id, 'payment_method_id' => $cod->id,
        ]));
    }

    public function test_the_pivot_carries_the_tenant_id(): void
    {
        $ship = $this->shipping($this->tenant);
        $cod = $this->payment($this->tenant);

        $this->actingAs($this->owner)
            ->put($this->url(), ['matrix' => [$ship->id => [$cod->id]]])
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('shipping_method_payment_method', [
            'tenant_id' => $this->tenant->id,
            'shipping_method_id' => $ship->id,
            'payment_method_id' => $cod->id,
        ]));
    }

    public function test_a_foreign_id_is_never_written(): void
    {
        $ship = $this->shipping($this->tenant);
        $cod = $this->payment($this->tenant);

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'shipping');
        $foreignShip = $this->shipping($other, ['name' => 'Cizí doprava']);
        $foreignPay = $this->payment($other, ['name' => 'Cizí platba']);

        // The payload smuggles a foreign shipping id, and a foreign payment id
        // under the tenant's own shipping method. Neither may reach the pivot.
        $this->actingAs($this->owner)
            ->put($this->url(), ['matrix' => [
                $ship->id => [$cod->id, $foreignPay->id],
                $foreignShip->id => [$cod->id],
            ]])
            ->assertRedirect();

        // The valid pair is written.
        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('shipping_method_payment_method', [
            'shipping_method_id' => $ship->id, 'payment_method_id' => $cod->id,
        ]));

        // The foreign payment under the own shipping method is not.
        $this->assertDatabaseMissing('shipping_method_payment_method', [
            'shipping_method_id' => $ship->id, 'payment_method_id' => $foreignPay->id,
        ]);

        // The foreign shipping method got no rows at all.
        $this->assertDatabaseMissing('shipping_method_payment_method', [
            'shipping_method_id' => $foreignShip->id,
        ]);
    }

    public function test_the_saved_matrix_is_what_payment_options_returns(): void
    {
        $ship = $this->shipping($this->tenant);
        $cod = $this->payment($this->tenant, ['name' => 'Dobírka']);
        $this->payment($this->tenant, ['name' => 'Převodem', 'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER]);

        $this->actingAs($this->owner)
            ->put($this->url(), ['matrix' => [$ship->id => [$cod->id]]])
            ->assertRedirect();

        // The admin screen and the checkout contract must agree: after saving
        // cod only, forShipping() returns cod only.
        $names = $this->context->runAs(
            $this->tenant,
            fn () => app(PaymentOptions::class)->forShipping($ship->id)->map->name()->all(),
        );

        $this->assertSame(['Dobírka'], $names);
    }

    public function test_an_empty_row_means_all_active_payments(): void
    {
        $ship = $this->shipping($this->tenant);
        $this->payment($this->tenant, ['name' => 'Dobírka']);
        $this->payment($this->tenant, ['name' => 'Převodem', 'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER]);

        // Save the matrix with no rows for this shipping method.
        $this->actingAs($this->owner)
            ->put($this->url(), ['matrix' => [$ship->id => []]])
            ->assertRedirect();

        $names = $this->context->runAs(
            $this->tenant,
            fn () => app(PaymentOptions::class)->forShipping($ship->id)->map->name()->all(),
        );

        $this->assertEqualsCanonicalizing(['Dobírka', 'Převodem'], $names);
    }

    public function test_the_permission_is_required(): void
    {
        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff', 'permissions' => ['products.view'], 'joined_at' => now(),
        ]);

        $this->actingAs($staff)->get($this->url())->assertForbidden();
        $this->actingAs($staff)->put($this->url(), ['matrix' => []])->assertForbidden();
    }
}
