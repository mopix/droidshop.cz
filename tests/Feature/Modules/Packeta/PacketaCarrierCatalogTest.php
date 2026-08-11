<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Packeta\Services\PacketaCarrierCatalog;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The partner-carrier feed behind the packeta_hd carrier_id select (task 5).
 *
 * Every failure mode returns null rather than throwing — a tenant who
 * already knows their carrier id must never be blocked by our own inability
 * to list them (task brief).
 */
class PacketaCarrierCatalogTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->create();
        $this->activateModule($this->tenant, 'shipping');
        $this->activateModule($this->tenant, 'packeta');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function makeMethod(array $settings, string $provider = ShippingMethod::PROVIDER_PACKETA_HD): ShippingMethod
    {
        return $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => $provider,
            'name' => 'Zásilkovna',
            'price' => 9900,
            'is_active' => true,
            'settings' => $settings,
        ]));
    }

    private function catalog(): PacketaCarrierCatalog
    {
        return app(PacketaCarrierCatalog::class);
    }

    private const CARRIERS_JSON = <<<'JSON'
        [
            {"id": "80", "name": "AT Rakouská pošta HD", "available": "true", "country": "at", "currency": "EUR"},
            {"id": "106", "name": "CZ Zásilkovna domů HD", "available": "true", "country": "cz", "currency": "CZK"},
            {"id": "999", "name": "CZ Zrušený dopravce HD", "available": "false", "country": "cz", "currency": "CZK"}
        ]
        JSON;

    public function test_no_configured_key_returns_null_without_any_http_call(): void
    {
        Http::fake();

        $result = $this->context->runAs($this->tenant, fn () => $this->catalog()->forTenant());

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_available_carriers_are_returned_sorted_by_country_then_name(): void
    {
        $this->makeMethod(['api_key' => 'key-1', 'eshop' => 'esh-1', 'api_password' => 'secret']);
        Http::fake(['pickup-point.api.packeta.com/*' => Http::response(self::CARRIERS_JSON)]);

        $result = $this->context->runAs($this->tenant, fn () => $this->catalog()->forTenant());

        // The unavailable Czech carrier (id 999) is dropped; Austria sorts
        // before the Czech Republic.
        $this->assertSame([
            ['id' => '80', 'name' => 'AT Rakouská pošta HD', 'country' => 'AT', 'currency' => 'EUR'],
            ['id' => '106', 'name' => 'CZ Zásilkovna domů HD', 'country' => 'CZ', 'currency' => 'CZK'],
        ], $result);
    }

    public function test_the_api_key_is_read_from_any_configured_packeta_family_method(): void
    {
        // Branch pickup (PROVIDER_PACKETA), not address delivery — apiKey()
        // takes whichever configured row it finds first.
        $this->makeMethod(['api_key' => 'key-1', 'eshop' => 'esh-1', 'api_password' => 'secret'], ShippingMethod::PROVIDER_PACKETA);
        Http::fake(['pickup-point.api.packeta.com/*' => Http::response(self::CARRIERS_JSON)]);

        $result = $this->context->runAs($this->tenant, fn () => $this->catalog()->forTenant());

        $this->assertNotNull($result);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'key-1'));
    }

    public function test_an_unauthorized_response_returns_null(): void
    {
        $this->makeMethod(['api_key' => 'bad-key', 'eshop' => 'esh-1', 'api_password' => 'secret']);
        Http::fake(['pickup-point.api.packeta.com/*' => Http::response(['detail' => 'Invalid API key'], 401)]);

        $result = $this->context->runAs($this->tenant, fn () => $this->catalog()->forTenant());

        $this->assertNull($result);
    }

    public function test_a_malformed_response_returns_null_instead_of_throwing(): void
    {
        $this->makeMethod(['api_key' => 'key-1', 'eshop' => 'esh-1', 'api_password' => 'secret']);
        Http::fake(['pickup-point.api.packeta.com/*' => Http::response('not json at all')]);

        $result = $this->context->runAs($this->tenant, fn () => $this->catalog()->forTenant());

        $this->assertNull($result);
    }

    public function test_a_successful_fetch_is_cached_and_the_feed_is_called_only_once(): void
    {
        $this->makeMethod(['api_key' => 'key-1', 'eshop' => 'esh-1', 'api_password' => 'secret']);
        Http::fake(['pickup-point.api.packeta.com/*' => Http::response(self::CARRIERS_JSON)]);

        $this->context->runAs($this->tenant, function () {
            $this->catalog()->forTenant();
            $this->catalog()->forTenant();
        });

        Http::assertSentCount(1);
    }

    /**
     * Cache::remember() reads back null exactly like "nothing cached" (Laravel
     * cannot tell the two apart), so a failed fetch is never sticky for the
     * full TTL — the next call retries for real. This is the property that
     * keeps a transient Packeta outage from locking a tenant out of the
     * select for a whole day once the feed recovers.
     */
    public function test_a_failed_fetch_is_retried_on_the_next_call_not_cached_as_a_failure(): void
    {
        $this->makeMethod(['api_key' => 'key-1', 'eshop' => 'esh-1', 'api_password' => 'secret']);

        // A sequence, not two separate Http::fake() calls: the second call
        // would only ADD a stub alongside the first rather than replacing
        // it, and the first-registered match wins — both requests would see
        // the same 500 (verified while writing this test).
        Http::fakeSequence('pickup-point.api.packeta.com/*')
            ->push('', 500)
            ->push(self::CARRIERS_JSON);

        $first = $this->context->runAs($this->tenant, fn () => $this->catalog()->forTenant());
        $this->assertNull($first);

        $second = $this->context->runAs($this->tenant, fn () => $this->catalog()->forTenant());
        $this->assertNotNull($second);
    }

    public function test_a_tenant_with_no_key_of_its_own_never_sees_another_tenants_carriers(): void
    {
        $this->makeMethod(['api_key' => 'key-1', 'eshop' => 'esh-1', 'api_password' => 'secret']);
        Http::fake(['pickup-point.api.packeta.com/*' => Http::response(self::CARRIERS_JSON)]);

        $other = Tenant::factory()->create();
        $this->activateModule($other, 'shipping');
        $this->activateModule($other, 'packeta');

        $result = $this->context->runAs($other, fn () => $this->catalog()->forTenant());

        $this->assertNull($result);
    }
}
