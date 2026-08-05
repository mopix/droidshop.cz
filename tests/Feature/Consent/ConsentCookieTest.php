<?php

namespace Tests\Feature\Consent;

use App\Core\Consent\Consent;
use App\Core\Consent\ConsentCategory;
use App\Core\Consent\ConsentCookie;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The decision lives in the visitor's own cookie. Everything here protects
 * one rule: when in doubt, ask again — never assume consent.
 */
class ConsentCookieTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('consent.version', '1');
        config()->set('consent.lifetime_days', 180);
    }

    public function test_no_cookie_means_undecided(): void
    {
        $this->assertNull(Consent::fromCookie(null));
        $this->assertNull(Consent::fromCookie(''));
    }

    /**
     * A tampered or truncated cookie must never take the storefront down —
     * every page on the shop reads this.
     */
    public function test_a_broken_cookie_means_undecided_not_an_exception(): void
    {
        $this->assertNull(Consent::fromCookie('{not json'));
        $this->assertNull(Consent::fromCookie('"just a string"'));
        $this->assertNull(Consent::fromCookie('{"v":"1"}'));
        $this->assertNull(Consent::fromCookie('{"v":"1","c":"nope","t":1}'));
    }

    /**
     * Consent to an older wording does not cover a newer one.
     */
    public function test_a_cookie_from_an_older_version_means_undecided(): void
    {
        $old = Consent::acceptAll()->toJson();
        config()->set('consent.version', '2');

        $this->assertNull(Consent::fromCookie($old));
    }

    public function test_accept_all_allows_every_refusable_category(): void
    {
        $consent = Consent::acceptAll();

        foreach (ConsentCategory::cases() as $category) {
            $this->assertTrue($consent->allows($category), $category->value.' should be allowed');
        }
    }

    /**
     * Refusing everything still leaves the necessary cookies: without them
     * nobody can log in or finish an order, and the law does not require
     * consent for them.
     */
    public function test_reject_all_still_allows_necessary(): void
    {
        $consent = Consent::rejectAll();

        $this->assertTrue($consent->allows(ConsentCategory::Necessary));
        $this->assertFalse($consent->allows(ConsentCategory::Analytics));
        $this->assertFalse($consent->allows(ConsentCategory::Marketing));
    }

    public function test_a_partial_choice_round_trips(): void
    {
        $consent = Consent::of(['analytics']);

        $restored = Consent::fromCookie($consent->toJson());

        $this->assertNotNull($restored);
        $this->assertTrue($restored->allows(ConsentCategory::Analytics));
        $this->assertFalse($restored->allows(ConsentCategory::Marketing));
    }

    /**
     * A category nobody offered must not become a stored decision — it would
     * suggest the visitor agreed to something that does not exist.
     */
    public function test_unknown_categories_are_dropped(): void
    {
        $consent = Consent::of(['analytics', 'vymyslena', 'necessary']);

        $this->assertSame(['analytics'], $consent->categories);
    }

    public function test_the_cookie_is_readable_by_javascript_and_lax(): void
    {
        ConsentCookie::queue(Consent::acceptAll());

        $queued = collect(app('cookie')->getQueuedCookies())
            ->firstWhere(fn ($cookie) => $cookie->getName() === ConsentCookie::NAME);

        $this->assertNotNull($queued);
        // Not httpOnly on purpose: the storefront's own JS reads it to hide
        // the banner before the page paints.
        $this->assertFalse($queued->isHttpOnly());
        $this->assertSame('lax', $queued->getSameSite());
    }

    /**
     * Laravel encrypts cookies by default, which would make this one opaque
     * to the very JavaScript that has to read it.
     */
    public function test_the_cookie_is_excluded_from_encryption(): void
    {
        $middleware = app(EncryptCookies::class);

        $this->assertTrue($middleware->isDisabled(ConsentCookie::NAME));
    }

    public function test_read_pulls_the_decision_off_the_request(): void
    {
        $request = Request::create('/', 'GET', cookies: [
            ConsentCookie::NAME => Consent::of(['marketing'])->toJson(),
        ]);

        $consent = ConsentCookie::read($request);

        $this->assertNotNull($consent);
        $this->assertTrue($consent->allows(ConsentCategory::Marketing));
        $this->assertFalse($consent->allows(ConsentCategory::Analytics));
    }
}
