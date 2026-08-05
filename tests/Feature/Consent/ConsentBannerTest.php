<?php

namespace Tests\Feature\Consent;

use App\Core\Consent\Consent;
use App\Core\Consent\ConsentCookie;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The banner has to satisfy two things that pull against each other: it must
 * appear for everyone who has not decided, and it must not cost the page
 * cache from wave 3.0 a single hit.
 */
class ConsentBannerTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('consent.version', '1');

        $this->artisan('modules:sync')->assertSuccessful();

        app(TenantContext::class)->forget();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'storefront');
    }

    public function test_a_visitor_without_a_decision_gets_the_banner(): void
    {
        $this->get('http://obchod.droidshop/')
            ->assertOk()
            ->assertSee('id="cookie-banner"', escape: false)
            ->assertSee('Přijmout vše')
            ->assertSee('Odmítnout vše');
    }

    /**
     * Consent is only valid if refusing is as easy as accepting. A grey
     * "reject" beside a coloured "accept" nudges the choice and makes it
     * unfree (EDPB Guidelines 03/2022). Asserted on the markup because it
     * cannot be eyeballed in review.
     */
    public function test_accept_and_reject_carry_identical_styling(): void
    {
        $html = $this->get('http://obchod.droidshop/')->assertOk()->getContent();

        preg_match_all('/<button[^>]*name="volba"[^>]*>/', (string) $html, $matches);

        $this->assertCount(2, $matches[0], 'expected exactly two decision buttons in the banner');

        $classes = array_map(function (string $button): string {
            preg_match('/class="([^"]*)"/', $button, $m);

            return $m[1] ?? '';
        }, $matches[0]);

        $this->assertSame($classes[0], $classes[1], 'accept and reject must be styled identically');
        $this->assertNotSame('', $classes[0]);
    }

    public function test_accepting_records_the_decision_and_returns(): void
    {
        $response = $this->from('http://obchod.droidshop/')
            ->post('http://obchod.droidshop/souhlas-cookies', ['volba' => 'vse']);

        $response->assertRedirect('http://obchod.droidshop/');
        $response->assertCookie(ConsentCookie::NAME);

        $consent = Consent::fromCookie($response->getCookie(ConsentCookie::NAME, false)?->getValue());

        $this->assertNotNull($consent);
        $this->assertSame(['analytics', 'marketing'], $consent->categories);
    }

    public function test_rejecting_records_an_explicit_refusal(): void
    {
        $response = $this->from('http://obchod.droidshop/')
            ->post('http://obchod.droidshop/souhlas-cookies', ['volba' => 'nic']);

        $consent = Consent::fromCookie($response->getCookie(ConsentCookie::NAME, false)?->getValue());

        $this->assertNotNull($consent, 'a refusal must be recorded, not left undecided');
        $this->assertSame([], $consent->categories);
    }

    /**
     * A per-category choice: whatever is not ticked is simply absent from the
     * request, which is exactly a refusal of it.
     */
    public function test_a_partial_choice_is_recorded(): void
    {
        $response = $this->from('http://obchod.droidshop/souhlas-cookies')
            ->post('http://obchod.droidshop/souhlas-cookies', ['kategorie' => ['analytics']]);

        $consent = Consent::fromCookie($response->getCookie(ConsentCookie::NAME, false)?->getValue());

        $this->assertSame(['analytics'], $consent?->categories);
    }

    /**
     * The whole point of the design: the banner must not make the page
     * uncacheable. Wave 3.0's throughput depends on it.
     */
    /**
     * A hit is measured by queries, not by a header — the middleware does not
     * advertise itself, and this is the same probe PageCacheMiddlewareTest
     * uses.
     */
    private function queriesFor(string $url): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get($url)->assertOk();

        DB::flushQueryLog();

        return $queries;
    }

    public function test_a_page_carrying_the_banner_is_still_cached(): void
    {
        $this->get('http://obchod.droidshop/')->assertOk();

        $queries = $this->queriesFor('http://obchod.droidshop/');

        // Resolving the tenant still costs a query or two; rendering must not.
        $this->assertLessThanOrEqual(3, $queries);

        $this->get('http://obchod.droidshop/')
            ->assertOk()
            ->assertSee('id="cookie-banner"', escape: false);
    }

    /**
     * And the mirror image: a visitor who decided must not cause a miss.
     * Making the consent cookie part of the cache key — or a reason to bypass
     * the cache — would lose the cache for most visitors, exactly the mistake
     * wave 3.0 avoided by dropping `has_cart`.
     */
    public function test_a_visitor_with_a_decision_still_gets_the_cached_page(): void
    {
        $this->get('http://obchod.droidshop/')->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $response = $this->withUnencryptedCookie(ConsentCookie::NAME, Consent::acceptAll()->toJson())
            ->get('http://obchod.droidshop/')
            ->assertOk();

        $this->assertLessThanOrEqual(3, $queries, 'the consent cookie must not cost a cache hit');
        // Still in the HTML — it is the same shared copy. The inline script
        // in the head hides it for this visitor before the first paint.
        $response->assertSee('id="cookie-banner"', escape: false);
    }

    public function test_the_footer_always_links_the_settings_screen(): void
    {
        $this->get('http://obchod.droidshop/')
            ->assertOk()
            ->assertSee('Nastavení cookies');
    }

    public function test_the_settings_screen_reflects_the_current_decision(): void
    {
        $this->withUnencryptedCookie(ConsentCookie::NAME, Consent::of(['analytics'])->toJson())
            ->get('http://obchod.droidshop/souhlas-cookies')
            ->assertOk()
            ->assertSee('Analytické')
            ->assertSee('Marketingové')
            ->assertSee('Nezbytné');
    }

    /**
     * Necessary cookies cannot be refused, so the checkbox must not pretend
     * otherwise.
     */
    public function test_the_necessary_category_cannot_be_switched_off(): void
    {
        $html = (string) $this->get('http://obchod.droidshop/souhlas-cookies')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*value="necessary"[^>]*disabled/',
            $html,
        );
    }

    /**
     * The settings screen reflects this visitor's own decision, so it is the
     * one storefront page that must never be served from the shared cache.
     */
    public function test_the_settings_screen_reflects_a_changed_decision(): void
    {
        $ticked = '/<input[^>]*value="analytics"[^>]*checked/';

        $allowed = (string) $this->withUnencryptedCookie(ConsentCookie::NAME, Consent::of(['analytics'])->toJson())
            ->get('http://obchod.droidshop/souhlas-cookies')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression($ticked, $allowed);

        $refused = (string) $this->withUnencryptedCookie(ConsentCookie::NAME, Consent::rejectAll()->toJson())
            ->get('http://obchod.droidshop/souhlas-cookies')
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression($ticked, $refused);
    }
}
