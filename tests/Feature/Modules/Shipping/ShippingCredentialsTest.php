<?php

namespace Tests\Feature\Modules\Shipping;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Services\ShippingMethodWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ShippingCredentialsTest extends TestCase
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
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 8900,
            'is_active' => true,
            'api_password' => 'super-secret-password',
            'api_key' => 'key-123',
            'eshop' => 'droidshop-demo',
            'default_weight_g' => 500,
            ...$overrides,
        ];
    }

    public function test_settings_are_encrypted_at_rest(): void
    {
        $tenant = Tenant::factory()->create();

        $method = $this->context->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 8900,
            'currency' => 'CZK',
            'settings' => ['api_password' => 'super-secret-password'],
        ]));

        $raw = DB::table('shipping_methods')->where('id', $method->id)->value('settings');

        $this->assertStringNotContainsString('super-secret-password', (string) $raw);
        $this->assertSame('super-secret-password', $method->fresh()->settings['api_password']);
    }

    public function test_pickup_settings_written_before_the_change_still_read(): void
    {
        // The migration re-encrypts existing plaintext rows; a method configured
        // in wave 1.3 must keep working after deploy.
        $tenant = Tenant::factory()->create();

        $method = $this->context->runAs($tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_PICKUP,
            'name' => 'Osobní odběr',
            'price' => 0,
            'currency' => 'CZK',
            'settings' => ['address' => 'Nádražní 1, Brno', 'hours' => 'Po–Pá 9–17'],
        ]));

        $this->assertSame('Nádražní 1, Brno', $method->fresh()->settings['address']);
    }

    public function test_blank_password_on_update_keeps_the_stored_one(): void
    {
        $method = $this->context->runAs($this->tenant, fn () => app(ShippingMethodWriter::class)->create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 8900,
            'is_active' => true,
            'api_password' => 'keep-me',
            'api_key' => 'key-1',
            'eshop' => 'shop-1',
            'default_weight_g' => 1000,
        ]));

        // The admin changes the eshop id, leaves the password blank.
        $this->actingAs($this->owner)
            ->put($this->url('/zpusoby-dopravy/'.$method->id), $this->payload([
                'eshop' => 'shop-2',
                'api_password' => '',
            ]))
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($method) {
            $fresh = $method->fresh();
            $this->assertSame('shop-2', $fresh->settings['eshop']);
            $this->assertSame('keep-me', $fresh->settings['api_password']);
        });
    }

    public function test_a_failed_update_does_not_flash_the_api_password_into_the_session(): void
    {
        $method = $this->context->runAs($this->tenant, fn () => app(ShippingMethodWriter::class)->create([
            'provider' => ShippingMethod::PROVIDER_PACKETA,
            'name' => 'Zásilkovna',
            'price' => 8900,
            'is_active' => true,
            'api_password' => 'keep-me',
            'api_key' => 'key-1',
            'eshop' => 'shop-1',
            'default_weight_g' => 1000,
        ]));

        // A valid api_password alongside an invalid price must fail validation
        // without leaving the credential sitting in the session's old input.
        $this->actingAs($this->owner)
            ->put($this->url('/zpusoby-dopravy/'.$method->id), $this->payload([
                'price' => -1,
                'api_password' => 'super-secret-password',
            ]))
            ->assertSessionHasErrors('price');

        $this->assertArrayNotHasKey('api_password', session()->getOldInput());
    }

    public function test_admin_props_never_carry_the_api_password(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-dopravy'), $this->payload())
            ->assertRedirect();

        $method = $this->context->runAs(
            $this->tenant,
            fn () => ShippingMethod::query()->where('name', 'Zásilkovna')->firstOrFail(),
        );

        $raw = DB::table('shipping_methods')->where('id', $method->id)->value('settings');
        $this->assertStringNotContainsString('super-secret-password', (string) $raw);

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Shipping/Index')
                ->where('shippingMethods.0.has_api_password', true)
                ->where('shippingMethods.0.packeta_api_key', 'key-123')
                ->where('shippingMethods.0.packeta_eshop', 'droidshop-demo')
                ->missing('shippingMethods.0.settings')
            )
            ->assertDontSee('super-secret-password');
    }

    /**
     * The admin form's fields for api_key/eshop/default_weight_g/api_password
     * are bound in Vue state regardless of the chosen provider (only their
     * visibility toggles). If the request transform ever fails to strip them
     * for a non-packeta provider, these are not columns on shipping_methods —
     * and the model has no $fillable guard — so create()/fill() blows up with
     * an "Unknown column" SQL error. This posts exactly what an unguarded
     * form would send for a flat method and asserts the write still succeeds.
     */
    public function test_a_flat_method_survives_stray_packeta_fields_in_the_request(): void
    {
        $strayFields = [
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
            'is_active' => true,
            'api_key' => 'stray-key',
            'eshop' => 'stray-eshop',
            'default_weight_g' => 750,
            'api_password' => 'stray-password',
        ];

        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-dopravy'), $strayFields)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $method = $this->context->runAs(
            $this->tenant,
            fn () => ShippingMethod::query()->where('name', 'Kurýr')->firstOrFail(),
        );

        $this->assertSame(ShippingMethod::PROVIDER_FLAT, $method->provider());

        $this->actingAs($this->owner)
            ->put($this->url('/zpusoby-dopravy/'.$method->id), $strayFields)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }
}
