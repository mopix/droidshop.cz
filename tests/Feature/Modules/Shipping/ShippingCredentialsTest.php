<?php

namespace Tests\Feature\Modules\Shipping;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        // Once a Packeta-family method has an api_key, opening the index
        // page tries the partner-carrier feed too (task 5) — faked here so
        // this test never makes a real network call, and its own result is
        // irrelevant to what this test is proving.
        Http::fake();

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
            // packeta_hd's own field (task 5) — the same "unguarded form"
            // scenario this test already covers for the older Packeta ones.
            'carrier_id' => 'stray-carrier',
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

    // --- packeta_hd (home delivery, task 5) ----------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function homeDeliveryPayload(array $overrides = []): array
    {
        return [
            'provider' => ShippingMethod::PROVIDER_PACKETA_HD,
            'name' => 'Zásilkovna – doručení na adresu',
            'price' => 9900,
            'is_active' => true,
            'api_password' => 'super-secret-password',
            'api_key' => 'key-123',
            'eshop' => 'droidshop-demo',
            'default_weight_g' => 1000,
            'carrier_id' => '106',
            ...$overrides,
        ];
    }

    public function test_a_shipping_method_can_be_stored_with_the_packeta_hd_provider_and_its_own_carrier_id(): void
    {
        Http::fake();

        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-dopravy'), $this->homeDeliveryPayload())
            ->assertRedirect();

        $method = $this->context->runAs(
            $this->tenant,
            fn () => ShippingMethod::query()->where('provider', ShippingMethod::PROVIDER_PACKETA_HD)->firstOrFail(),
        );

        $this->assertSame('106', $method->settings['carrier_id']);
        $this->assertSame('106', $method->packetaCarrierId());
        // eshop/api_password now resolve through the same widened accessors
        // branch pickup already used (task 5) — proof the widening actually
        // reaches this row, not just that the setting was saved.
        $this->assertSame('droidshop-demo', $method->packetaEshop());
        $this->assertTrue($method->apiPasswordSet());
    }

    public function test_creating_a_home_delivery_method_without_a_carrier_id_is_refused(): void
    {
        Http::fake();

        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-dopravy'), $this->homeDeliveryPayload(['carrier_id' => null]))
            ->assertSessionHasErrors('carrier_id');
    }

    /**
     * Branch pickup (PROVIDER_PACKETA) has no carrier to name — the field
     * must stay optional for it, or a tenant who never touches home
     * delivery would be blocked on an unrelated field.
     */
    public function test_a_plain_packeta_method_does_not_require_a_carrier_id(): void
    {
        Http::fake();

        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-dopravy'), $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_a_home_delivery_methods_admin_props_expose_its_carrier_id_and_hide_the_password(): void
    {
        Http::fake();

        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-dopravy'), $this->homeDeliveryPayload())
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Shipping/Index')
                ->where('shippingMethods.0.provider', ShippingMethod::PROVIDER_PACKETA_HD)
                ->where('shippingMethods.0.has_api_password', true)
                ->where('shippingMethods.0.packeta_carrier_id', '106')
                ->missing('shippingMethods.0.settings')
            )
            ->assertDontSee('super-secret-password');
    }

    /**
     * The select is filled from a real feed call (task 5 brief) — proven
     * here end to end through the admin route, not just at the service
     * level (PacketaCarrierCatalogTest covers that in isolation).
     */
    public function test_the_index_exposes_the_partner_carrier_list_when_the_feed_succeeds(): void
    {
        Http::fake(['pickup-point.api.packeta.com/*' => Http::response(
            '[{"id":"106","name":"CZ Zásilkovna domů HD","available":"true","country":"cz","currency":"CZK"}]'
        )]);

        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-dopravy'), $this->homeDeliveryPayload())
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Shipping/Index')
                ->where('packetaCarriers', [
                    ['id' => '106', 'name' => 'CZ Zásilkovna domů HD', 'country' => 'CZ', 'currency' => 'CZK'],
                ])
            );
    }

    /**
     * A tenant who already knows their carrier id must not be blocked by our
     * inability to reach Packeta's feed (task brief) — the admin screen
     * itself must still render, with a null list the form degrades on.
     */
    public function test_the_index_exposes_a_null_carrier_list_when_the_feed_is_unreachable(): void
    {
        Http::fake(['pickup-point.api.packeta.com/*' => Http::response('', 500)]);

        $this->actingAs($this->owner)
            ->post($this->url('/zpusoby-dopravy'), $this->homeDeliveryPayload())
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Shipping/Index')
                ->where('packetaCarriers', null)
            );
    }
}
