<?php

namespace Tests\Feature\Modules\Analytics;

use App\Core\Consent\Consent;
use App\Core\Consent\ConsentCookie;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The hard rule of wave 3.3: nothing contacts a third party before the
 * visitor consents.
 *
 * Asserted on the raw HTML rather than on browser behaviour, because the HTML
 * is what the server controls and what a page-cache entry freezes. A vendor
 * hostname appearing in a cached page would mean every later visitor gets it
 * too, regardless of what they decided.
 */
class TrackingCodesTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private const VENDOR_HOSTS = [
        'googletagmanager.com',
        'c.seznam.cz',
        'connect.facebook.net',
    ];

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('consent.version', '1');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'storefront');
        $this->activateModule($this->tenant, 'analytics');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function configure(array $values): void
    {
        $this->context->runAs($this->tenant, fn () => app(SettingsService::class)->setMany('analytics', $values));
    }

    private function homepage(?Consent $consent = null): string
    {
        $request = $consent === null
            ? $this
            : $this->withUnencryptedCookie(ConsentCookie::NAME, $consent->toJson());

        return (string) $request->get('http://obchod.droidshop/')->assertOk()->getContent();
    }

    /**
     * No markup that makes the browser fetch from a vendor while parsing the
     * page: script/img/iframe src, link href, preconnect, dns-prefetch.
     *
     * Deliberately NOT "the hostname appears nowhere in the HTML". The loader
     * carries vendor URLs as strings inside functions that only run once the
     * cookie allows it, and rewriting them to dodge a substring check would
     * be obfuscation rather than a guarantee. What this asserts is the part
     * the server controls and a cache entry freezes: nothing here fires a
     * request on its own.
     *
     * That the JavaScript then honours the decision is verified by hand on
     * the demo (wave 3.3 task 7) and will be covered by Playwright in 3.4 —
     * a PHP test cannot execute it.
     */
    private function assertNoVendorRequests(string $html): void
    {
        foreach (self::VENDOR_HOSTS as $host) {
            $this->assertDoesNotMatchRegularExpression(
                '/<(?:script|img|iframe|link)\b[^>]*\b(?:src|href)\s*=\s*["\'][^"\']*'.preg_quote($host, '/').'/i',
                $html,
                "nothing may fetch from {$host} before consent",
            );
        }
    }

    /**
     * The single most important test in the wave. A vendor hostname in the
     * markup means a request leaves the browser, and a request before consent
     * is the violation — whether or not a cookie comes back.
     */
    public function test_nothing_reaches_a_vendor_before_a_decision(): void
    {
        $this->configure([
            'ga4_measurement_id' => 'G-ABCD1234',
            'sklik_retargeting_id' => '12345',
            'meta_pixel_id' => '99887766',
        ]);

        $this->assertNoVendorRequests($this->homepage());
    }

    /**
     * And after an explicit refusal, which is a different state from
     * "undecided" and must behave the same way.
     */
    public function test_nothing_reaches_a_vendor_after_a_refusal(): void
    {
        $this->configure([
            'ga4_measurement_id' => 'G-ABCD1234',
            'sklik_retargeting_id' => '12345',
            'meta_pixel_id' => '99887766',
        ]);

        $this->assertNoVendorRequests($this->homepage(Consent::rejectAll()));
    }

    /**
     * The ids are the tenant's configuration, identical for every visitor, so
     * they may be rendered server-side — that is what keeps the page
     * cacheable. What must never be rendered server-side is the decision.
     */
    public function test_configured_ids_are_in_the_page_as_configuration(): void
    {
        $this->configure([
            'ga4_measurement_id' => 'G-ABCD1234',
            'meta_pixel_id' => '99887766',
        ]);

        $html = $this->homepage();

        $this->assertStringContainsString('id="tracking-config"', $html);
        $this->assertStringContainsString('G-ABCD1234', $html);
        $this->assertStringContainsString('99887766', $html);
    }

    /**
     * The page must be byte-identical whatever the visitor decided; otherwise
     * a cached copy would hand one visitor's decision to the next.
     */
    public function test_the_html_does_not_change_with_the_decision(): void
    {
        $this->configure(['ga4_measurement_id' => 'G-ABCD1234']);

        $undecided = $this->homepage();
        $accepted = $this->homepage(Consent::acceptAll());
        $refused = $this->homepage(Consent::rejectAll());

        $this->assertSame($undecided, $accepted);
        $this->assertSame($undecided, $refused);
    }

    public function test_a_tenant_without_any_id_gets_no_tracking_block(): void
    {
        $html = $this->homepage();

        $this->assertStringNotContainsString('id="tracking-config"', $html);
        // But the banner is still there: necessary cookies exist regardless.
        $this->assertStringContainsString('id="cookie-banner"', $html);
    }

    public function test_a_shop_without_the_module_gets_no_tracking_block(): void
    {
        $other = Tenant::factory()->withDomain('jiny.droidshop')->create();
        $this->activateModule($other, 'storefront');

        $html = (string) $this->get('http://jiny.droidshop/')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="tracking-config"', $html);
        $this->assertStringContainsString('id="cookie-banner"', $html);
    }

    /**
     * Consent Mode v2 is what makes a GA4 property pair with Google Ads in
     * the EU since 2024, and the default-denied call has to come before
     * gtag.js — not after.
     */
    public function test_ga4_declares_consent_mode_defaults_before_loading(): void
    {
        $this->configure(['ga4_measurement_id' => 'G-ABCD1234']);

        $html = $this->homepage();

        $defaultAt = strpos($html, "'consent', 'default'");
        $loadAt = strpos($html, 'googletagmanager.com/gtag/js');

        $this->assertNotFalse($defaultAt, 'the default-denied consent call is missing');
        $this->assertNotFalse($loadAt, 'the loader for gtag.js is missing');
        $this->assertLessThan($loadAt, $defaultAt, 'consent defaults must be declared before gtag.js loads');
    }

    /**
     * A measurement id is tenant configuration; it must never leak onto
     * another shop's pages.
     */
    public function test_one_shops_ids_never_appear_on_another(): void
    {
        $this->configure(['ga4_measurement_id' => 'G-ABCD1234']);

        $other = Tenant::factory()->withDomain('jiny.droidshop')->create();
        $this->activateModule($other, 'storefront');
        $this->activateModule($other, 'analytics');

        $html = (string) $this->get('http://jiny.droidshop/')->assertOk()->getContent();

        $this->assertStringNotContainsString('G-ABCD1234', $html);
    }

    /**
     * The banner must keep working on a page that also carries tracking
     * configuration — the two scripts sit next to each other.
     */
    public function test_the_page_is_still_cacheable_with_tracking_configured(): void
    {
        $this->configure(['ga4_measurement_id' => 'G-ABCD1234']);

        $first = $this->homepage();
        $second = $this->homepage();

        $this->assertSame($first, $second);
        $this->assertStringContainsString('id="cookie-banner"', $second);
    }
}
