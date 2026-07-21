<?php

namespace Tests\Feature\Modules\Shipping;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Services\ShippingMethodWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ShippingMethodAdminTest extends TestCase
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
        // The Vue pages are built by the frontend agent; assert on the Inertia
        // payload without requiring the component file to be on disk yet.

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'shipping');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/shipping'.$path;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
            'is_active' => true,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function make(Tenant $tenant, array $attributes = []): ShippingMethod
    {
        return $this->context->runAs($tenant, fn () => app(ShippingMethodWriter::class)->create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
            'is_active' => true,
            ...$attributes,
        ]));
    }

    private function staffWithout(): User
    {
        $staff = User::factory()->create();
        // A member who runs the shop's products but was never given the
        // shipping right.
        $this->tenant->users()->attach($staff, [
            'role' => 'staff', 'permissions' => ['products.view'], 'joined_at' => now(),
        ]);

        return $staff;
    }

    public function test_the_index_lists_the_tenants_own_methods_only(): void
    {
        $this->make($this->tenant, ['name' => 'Moje']);

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'shipping');
        $this->make($other, ['name' => 'Cizí']);

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Shipping/Index')
                ->has('shippingMethods', 1)
                ->where('shippingMethods.0.name', 'Moje')
            );
    }

    public function test_a_method_is_created_with_the_price_as_submitted_haleire(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-dopravy'), $this->payload(['price' => 12345]))
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('shipping_methods', [
            'name' => 'Kurýr',
            'price' => 12345,
        ]));
    }

    public function test_a_method_is_updated_and_deleted(): void
    {
        $method = $this->make($this->tenant);

        $this->actingAs($this->owner)
            ->put($this->url('/zpusoby-dopravy/'.$method->id), $this->payload(['name' => 'Nový název', 'price' => 8000]))
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('shipping_methods', [
            'id' => $method->id, 'name' => 'Nový název', 'price' => 8000,
        ]));

        $this->actingAs($this->owner)
            ->delete($this->url('/zpusoby-dopravy/'.$method->id))
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseMissing('shipping_methods', [
            'id' => $method->id,
        ]));
    }

    public function test_a_negative_price_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-dopravy'), $this->payload(['price' => -1]))
            ->assertSessionHasErrors('price');
    }

    public function test_pickup_requires_an_address_and_stores_it_in_settings(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-dopravy'), $this->payload([
                'provider' => ShippingMethod::PROVIDER_PICKUP,
                'name' => 'Osobní odběr',
            ]))
            ->assertSessionHasErrors(['settings.street', 'settings.city', 'settings.zip']);

        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-dopravy'), $this->payload([
                'provider' => ShippingMethod::PROVIDER_PICKUP,
                'name' => 'Osobní odběr',
                'settings' => [
                    'street' => 'Nádražní 1',
                    'city' => 'Praha',
                    'zip' => '11000',
                    'opening_hours' => 'Po–Pá 9–17',
                ],
            ]))
            ->assertRedirect();

        $method = $this->context->runAs($this->tenant, fn () => ShippingMethod::query()->where('name', 'Osobní odběr')->firstOrFail());

        $this->assertSame('Praha', $method->settings['city']);
    }

    public function test_reorder_gaps_positions_and_is_tenant_scoped(): void
    {
        $first = $this->make($this->tenant, ['name' => 'První', 'position' => 10]);
        $second = $this->make($this->tenant, ['name' => 'Druhá', 'position' => 20]);

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'shipping');
        $foreign = $this->make($other, ['name' => 'Cizí', 'position' => 50]);

        // Submit both own ids reversed, and the foreign id too — the foreign one
        // must not move.
        $this->actingAs($this->owner)
            ->put($this->url('/zpusoby-dopravy/poradi'), ['ids' => [$second->id, $first->id, $foreign->id]])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($first, $second) {
            $this->assertSame(20, $first->fresh()->position);
            $this->assertSame(10, $second->fresh()->position);
        });

        $this->context->runAs($other, fn () => $this->assertSame(50, $foreign->fresh()->position));
    }

    public function test_a_method_of_another_shop_is_not_reachable(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'shipping');
        $foreign = $this->make($other, ['name' => 'Cizí']);

        $this->actingAs($this->owner)
            ->put($this->url('/zpusoby-dopravy/'.$foreign->id), $this->payload())
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->delete($this->url('/zpusoby-dopravy/'.$foreign->id))
            ->assertNotFound();
    }

    public function test_a_member_without_the_permission_cannot_write(): void
    {
        $staff = $this->staffWithout();
        $method = $this->make($this->tenant);

        $this->actingAs($staff)->get($this->url())->assertForbidden();
        $this->actingAs($staff)->post($this->url('/zpusoby-dopravy'), $this->payload())->assertForbidden();
        $this->actingAs($staff)->put($this->url('/zpusoby-dopravy/'.$method->id), $this->payload())->assertForbidden();
        $this->actingAs($staff)->delete($this->url('/zpusoby-dopravy/'.$method->id))->assertForbidden();
        $this->actingAs($staff)->put($this->url('/zpusoby-dopravy/poradi'), ['ids' => [$method->id]])->assertForbidden();
    }
}
