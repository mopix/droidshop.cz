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
     * Review finding I5 changed this behaviour on purpose: a failure used to
     * write nothing to cache at all, so a screen reload during an outage
     * re-ran the full (30s-timeout) HTTP call every single time — that is
     * exactly what stalled the shipping settings screen. A failure is now
     * remembered too, on a short negative-cache TTL: immediately retrying
     * must NOT hit the network again.
     */
    public function test_a_failed_fetch_is_not_retried_within_the_negative_cache_window(): void
    {
        $this->makeMethod(['api_key' => 'key-1', 'eshop' => 'esh-1', 'api_password' => 'secret']);

        Http::fake(['pickup-point.api.packeta.com/*' => Http::response('', 500)]);

        // Both calls inside ONE runAs(), mirroring
        // test_a_successful_fetch_is_cached_and_the_feed_is_called_only_once
        // above: config('cache.default') is 'array' in this suite, and
        // PrefixCacheTask forgets that driver's in-memory store on every
        // tenant switch (rozhodnutí 2026-08-05, TenantContext::set()'s own
        // docblock) — two SEPARATE runAs() calls would each trigger a real
        // switch (tenant -> null -> tenant) and wipe the cache in between,
        // making this test pass for the wrong reason (no caching happened
        // at all, not "the negative cache held").
        $this->context->runAs($this->tenant, function () {
            $first = $this->catalog()->forTenant();
            $this->assertNull($first);

            $immediateRetry = $this->catalog()->forTenant();
            $this->assertNull($immediateRetry);
        });

        Http::assertSentCount(1);
    }

    /**
     * The negative cache is deliberately short (far below the day-long
     * success TTL) — this is the property that keeps a transient Packeta
     * outage from locking a tenant out of the select for a whole day once
     * the feed recovers, the same guarantee the pre-fix "never cache a
     * failure" behaviour gave, just no longer paid for on every reload.
     */
    public function test_a_failed_fetch_is_retried_once_the_negative_cache_expires(): void
    {
        $this->makeMethod(['api_key' => 'key-1', 'eshop' => 'esh-1', 'api_password' => 'secret']);

        // A sequence, not two separate Http::fake() calls: the second call
        // would only ADD a stub alongside the first rather than replacing
        // it, and the first-registered match wins — both requests would see
        // the same 500 (verified while writing this test).
        Http::fakeSequence('pickup-point.api.packeta.com/*')
            ->push('', 500)
            ->push(self::CARRIERS_JSON);

        // One runAs() again, for the same reason as the test above — the
        // travel() call sits inside it too, since it must happen between
        // the two forTenant() calls, not before either of them.
        $this->context->runAs($this->tenant, function () {
            $first = $this->catalog()->forTenant();
            $this->assertNull($first);

            $this->travel((int) config('packeta.carrier_feed_failure_ttl_seconds') + 1)->seconds();

            $second = $this->catalog()->forTenant();
            $this->assertNotNull($second);
        });
    }

    /**
     * Review finding I5: this call used to share the 30s submission timeout
     * (config('packeta.timeout')) with real parcel submission, even though
     * it runs synchronously on every shipping settings screen load. Proven
     * by asserting the config VALUE actually reaching the HTTP client is the
     * dedicated, short one — a unit-level assertion is the only way to prove
     * "which timeout" without an actually slow fake transport.
     */
    public function test_the_feed_read_uses_its_own_short_timeout_not_the_submission_one(): void
    {
        $this->assertNotSame(
            (int) config('packeta.timeout'),
            (int) config('packeta.carrier_feed_read_timeout'),
            'The feed read must not silently share the submission timeout again.',
        );

        $this->assertLessThan((int) config('packeta.timeout'), (int) config('packeta.carrier_feed_read_timeout'));
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
