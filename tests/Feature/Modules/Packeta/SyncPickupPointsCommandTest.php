<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Packeta\Models\PickupPoint;
use Modules\Shipping\Models\ShippingMethod;
use Tests\TestCase;

/**
 * packeta:sync-points (wave 2.5, task 5) has no ambient tenant
 * (NotTenantAware) but its fallback key lookup reads a tenant-owned,
 * encrypted column. These tests exercise that fallback with the tenant
 * context explicitly empty, the way the scheduler runs it.
 */
class SyncPickupPointsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['packeta.feed_min_points' => 1]);
        app(TenantContext::class)->forget();
    }

    private function fakeFeed(): void
    {
        Http::fake(['*' => Http::response(['data' => [[
            'id' => '1', 'name' => 'Večerka', 'city' => 'Brno',
            'street' => 'Hlavní 1', 'zip' => '60200', 'country' => 'cz',
        ]]])]);
    }

    public function test_command_fails_with_no_key_configured_anywhere(): void
    {
        config(['packeta.feed_api_key' => null]);

        $this->artisan('packeta:sync-points')
            ->assertExitCode(1)
            ->expectsOutputToContain('No Packeta API key');
    }

    public function test_command_uses_the_platform_wide_key_when_configured(): void
    {
        config(['packeta.feed_api_key' => 'platform-key']);
        $this->fakeFeed();

        $this->artisan('packeta:sync-points')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'platform-key'));
    }

    public function test_command_falls_back_to_a_tenant_stored_key_without_an_ambient_tenant(): void
    {
        config(['packeta.feed_api_key' => null]);

        $tenant = Tenant::factory()->create();

        // Written while the tenant context is set (settings is an encrypted
        // cast, keyed off APP_KEY — not tenant-specific — but the row itself
        // is BelongsToTenant and needs an ambient tenant to be created).
        app(TenantContext::class)->runAs($tenant, function () {
            ShippingMethod::create([
                'provider' => ShippingMethod::PROVIDER_PACKETA,
                'name' => 'Zásilkovna',
                'price' => 8900,
                'currency' => 'CZK',
                'is_active' => true,
                'settings' => ['api_key' => 'tenant-key', 'eshop' => 'shop1'],
            ]);
        });

        // Ambient tenant context is empty here, matching how the scheduler
        // runs a NotTenantAware command. Reading ShippingMethod::settings
        // (an encrypted:array cast) across the tenant boundary must not
        // throw MissingTenantContext or fail to decrypt.
        $this->assertNull(app(TenantContext::class)->id());

        $this->fakeFeed();

        $this->artisan('packeta:sync-points')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'tenant-key'));
        $this->assertSame(1, PickupPoint::where('code', '1')->count());
    }
}
