<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Money\Money;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Checkout\Models\Cart;
use Modules\Discounts\Models\Discount;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The discount field, driven the way a shopper without JavaScript drives it:
 * a real POST that redirects back to a freshly rendered page. Every price and
 * cookie assertion here reads whatever the server actually produced — no
 * follow-up fetch, matching how CartPageTest exercises the rest of `/kosik`
 * (spec §16.3, .claude/rules/storefront-rendering.md).
 */
class CartDiscountFormTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        foreach (['storefront', 'checkout', 'products', 'categories', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    /**
     * A product priced at 1 000,00 Kč, plus a SLEVA10 coupon worth 10 % off
     * the whole cart — created inside the tenant so both are tenant-scoped
     * the same way a real shop's data would be.
     */
    private function seedProductAndCode(): Product
    {
        return $this->context->runAs($this->tenant, function (): Product {
            $product = app(ProductWriter::class)->create([
                'name' => 'Testovací produkt',
                'price' => 100_000,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'stock_qty' => 10,
            ]);

            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            return $product;
        });
    }

    private function cartTokenInDb(): string
    {
        return $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail()->token);
    }

    /**
     * Money::format() uses NumberFormatter's own grouping and non-breaking
     * spaces (cs_CZ groups digits with U+00A0), so assertions render the
     * expectation through the exact same formatter rather than guessing the
     * literal bytes — same helper CartPageTest uses.
     */
    private function czk(int $minorUnits): string
    {
        return (new Money($minorUnits, 'CZK'))->format();
    }

    /**
     * The rejection reason for an invalid code is flashed onto the session
     * (Laravel's ordinary $errors/old() mechanism — see
     * CartDiscountController::apply()), not stored on the cart. Reading it
     * back therefore needs the session cookie carried forward exactly like
     * cart_token is above — the test client does not persist cookies between
     * calls on its own (same reason CartPageTest passes cart_token by hand).
     */
    private function sessionCookieValue(TestResponse $response): string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie->getValue();
            }
        }

        $this->fail('Response carried no session cookie to propagate.');
    }

    public function test_applying_a_code_without_javascript_reduces_the_rendered_total(): void
    {
        $product = $this->seedProductAndCode();

        $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertRedirect();

        $token = $this->cartTokenInDb();

        $apply = $this->withCookie('cart_token', $token)
            ->post($this->url('/kosik/sleva'), ['code' => 'SLEVA10']);

        $apply->assertRedirect($this->url('/kosik'));

        $page = $this->withCookie('cart_token', $token)->get($this->url('/kosik'));

        $page->assertOk();
        $page->assertSee('SLEVA10');
        $page->assertSee($this->czk(90_000), false); // 10 % off 1 000,00 Kč
    }

    public function test_an_unknown_code_is_not_stored_and_the_reason_is_shown(): void
    {
        $this->seedProductAndCode();

        $apply = $this->post($this->url('/kosik/sleva'), ['code' => 'NEEXISTUJE']);
        $apply->assertRedirect();

        $token = $this->cartTokenInDb();

        $page = $this->withCookie('cart_token', $token)
            ->withCookie(config('session.cookie'), $this->sessionCookieValue($apply))
            ->get($this->url('/kosik'));

        $page->assertOk();
        $page->assertSee('Slevový kód neplatí');
        $this->assertDatabaseMissing('carts', ['coupon_code' => 'NEEXISTUJE']);
    }

    public function test_removing_a_code_restores_the_full_total(): void
    {
        $product = $this->seedProductAndCode();

        $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
        $token = $this->cartTokenInDb();

        $this->withCookie('cart_token', $token)
            ->post($this->url('/kosik/sleva'), ['code' => 'SLEVA10'])
            ->assertRedirect();

        $this->assertSame(
            'SLEVA10',
            $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail()->coupon_code),
        );

        $remove = $this->withCookie('cart_token', $token)->post($this->url('/kosik/sleva/zrusit'));
        $remove->assertRedirect($this->url('/kosik'));

        $this->assertDatabaseMissing('carts', ['coupon_code' => 'SLEVA10']);

        $page = $this->withCookie('cart_token', $token)->get($this->url('/kosik'));
        $page->assertSee($this->czk(100_000)); // full price again, no discount line
    }

    public function test_the_field_is_absent_when_the_module_is_off(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);

        foreach (['storefront', 'checkout', 'products'] as $module) {
            $this->activateModule($other, $module);
        }

        $page = $this->get('http://shop2.droidshop/kosik');

        $page->assertOk();
        $page->assertDontSee('Slevový kód');
    }

    /**
     * The field being absent from the rendered page is not the same
     * guarantee as the endpoint refusing to write — /kosik/sleva is only
     * gated by `module:checkout`, so a direct POST (bypassing the UI
     * entirely) must be refused by the controller itself, not merely left
     * unreachable by the missing form (review finding, wave 2.6).
     */
    public function test_posting_a_code_is_ignored_when_the_module_is_off(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);

        foreach (['storefront', 'checkout', 'products'] as $module) {
            $this->activateModule($other, $module);
        }

        $apply = $this->post('http://shop2.droidshop/kosik/sleva', ['code' => 'ANYTHING']);
        $apply->assertRedirect();

        $this->assertDatabaseMissing('carts', ['coupon_code' => 'ANYTHING']);
    }

    /**
     * Final review (wave 2.6): the render that discovers a stored code has gone
     * stale clears it, so "a code still on the cart at submit" means "it was
     * valid at the last render" by construction — which is what lets
     * OrderPlacer refuse on ANY rejection without turning an old code into a
     * dead end. The reason has to be on that same render: clearing it silently
     * would be worse than the bug.
     */
    public function test_a_render_clears_a_stale_code_and_shows_the_reason(): void
    {
        $product = $this->seedProductAndCode();

        $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
        $token = $this->cartTokenInDb();

        $this->withCookie('cart_token', $token)
            ->post($this->url('/kosik/sleva'), ['code' => 'SLEVA10'])
            ->assertRedirect();

        $this->assertSame(
            'SLEVA10',
            $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail()->coupon_code),
        );

        // The coupon's validity ends while the cart sits open — the everyday
        // version of ends_at crossing midnight.
        $this->context->runAs(
            $this->tenant,
            fn () => Discount::query()->firstOrFail()->forceFill(['ends_at' => now()->subMinute()])->save(),
        );

        $page = $this->withCookie('cart_token', $token)->get($this->url('/kosik'));

        $page->assertOk();
        $page->assertSee('Slevový kód neplatí');
        $page->assertSee('platnost kódu skončila.');
        $page->assertSee($this->czk(100_000), false); // full price again
        $page->assertDontSee('Uplatněn slevový kód');

        // Gone from the cart, so a submit cannot be refused for it later.
        $this->assertNull(
            $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail()->coupon_code),
        );
    }

    /**
     * Final review (wave 2.6): the endpoint answered an unlimited number of
     * guesses, and its rejection reasons distinguish "takový kód neexistuje"
     * from "kód je vyčerpaný" / "platnost kódu skončila" — a dictionary oracle
     * for a tenant's whole coupon list. ApplyDiscountRequest now throttles ten
     * attempts a minute per cart+IP, the same RateLimiter pattern
     * Modules\Customers\Http\Requests\LoginRequest uses.
     *
     * The eleventh attempt deliberately carries a code that DOES exist: if the
     * request had reached the lookup at all, the cart would be carrying it
     * afterwards.
     */
    public function test_the_eleventh_code_attempt_in_a_minute_is_refused_without_a_lookup(): void
    {
        $product = $this->seedProductAndCode();

        $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
        $token = $this->cartTokenInDb();

        for ($i = 0; $i < 10; $i++) {
            $this->withCookie('cart_token', $token)
                ->post($this->url('/kosik/sleva'), ['code' => 'HADANKA'.$i])
                ->assertRedirect();
        }

        $throttled = $this->withCookie('cart_token', $token)
            ->post($this->url('/kosik/sleva'), ['code' => 'SLEVA10']);

        $throttled->assertRedirect();
        $throttled->assertSessionHasErrors('code');

        $this->assertStringContainsString(
            'Příliš mnoho pokusů',
            (string) session('errors')->first('code'),
        );

        // Never looked up, so never stored — the valid code did not get in.
        $this->assertDatabaseMissing('carts', ['coupon_code' => 'SLEVA10']);
    }

    /**
     * Re-review of the final-review fix: one composed key (tenant + cart token
     * + IP) throttles nothing durable, because changing ANY component mints a
     * fresh bucket. `POST /kosik` is unthrottled and hands out a brand new
     * valid cart token, so an attacker spends ten guesses, rotates, and repeats
     * — about eleven requests per ten guesses, unbounded. Cookie encryption
     * stops a forged token, not a minted one.
     *
     * Two independent limiters now apply and either one refuses: a wide per-IP
     * ceiling that survives rotation, plus the tight per-cart one.
     */
    public function test_rotating_the_cart_token_does_not_buy_a_fresh_allowance(): void
    {
        $this->seedProductAndCode();

        // Thirty attempts spread over three cart tokens, ten each — so the
        // per-cart limiter never fires and every one of them lands on the IP
        // limiter instead. This is exactly the rotation attack.
        foreach (['cart-a', 'cart-b', 'cart-c'] as $cartToken) {
            for ($i = 0; $i < 10; $i++) {
                $this->withCookie('cart_token', $cartToken)
                    ->post($this->url('/kosik/sleva'), ['code' => 'HADANKA'.$i])
                    ->assertRedirect();
            }
        }

        // A fourth fresh token — untouched by the per-cart limiter, and under
        // the old single key this would have been a clean slate.
        $throttled = $this->withCookie('cart_token', 'cart-d')
            ->post($this->url('/kosik/sleva'), ['code' => 'SLEVA10']);

        $throttled->assertRedirect();
        $throttled->assertSessionHasErrors('code');

        $this->assertStringContainsString(
            'Příliš mnoho pokusů',
            (string) session('errors')->first('code'),
        );

        $this->assertDatabaseMissing('carts', ['coupon_code' => 'SLEVA10']);
    }
}
