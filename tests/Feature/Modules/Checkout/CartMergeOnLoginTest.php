<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Checkout\Models\Cart;
use Modules\Customers\Models\Customer;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Cart-merge-on-login (rozhodnutí 7): an anonymous cart's identity is a
 * cookie token, so the moment a shopper signs in, whatever that cookie
 * points at has to reconcile with whatever the account already owns. Driven
 * through the real POST /prihlaseni flow, not by calling CartMerger
 * directly — the whole point is to prove Illuminate\Auth\Events\Login is
 * actually wired to it (it previously was not).
 */
class CartMergeOnLoginTest extends TestCase
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

        foreach (['storefront', 'customers', 'checkout'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path, ?Tenant $tenant = null): string
    {
        $host = $tenant?->domains()->first()?->domain ?? 'shop1.droidshop';

        return 'http://'.$host.$path;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 10_000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            ...$attributes,
        ]));
    }

    private function makeCustomer(Tenant $tenant, string $email): Customer
    {
        return $this->context->runAs($tenant, fn () => Customer::factory()->create(['email' => $email]));
    }

    private function login(string $email, ?string $anonToken = null, ?Tenant $tenant = null): TestResponse
    {
        if ($anonToken !== null) {
            $this->withCookie('cart_token', $anonToken);
        }

        return $this->post($this->url('/prihlaseni', $tenant), [
            'email' => $email,
            'password' => 'heslo12345',
        ]);
    }

    private function cartsFor(Tenant $tenant): Collection
    {
        return $this->context->runAs($tenant, fn () => Cart::query()->get());
    }

    /**
     * Cart::items() is BelongsToTenant-scoped, so reading it must run under
     * the same tenant the cart itself belongs to — not whichever tenant
     * happens to be ambient in the test.
     *
     * @return array<int, int> product_id => quantity
     */
    private function itemsOf(Tenant $tenant, Cart $cart): array
    {
        return $this->context->runAs($tenant, fn () => $cart->items()
            ->get()
            ->mapWithKeys(fn ($item) => [(int) $item->product_id => (int) $item->quantity])
            ->all());
    }

    public function test_an_anonymous_cart_becomes_the_customers_cart_when_they_have_none(): void
    {
        $product = $this->makeProduct($this->tenant);
        $customer = $this->makeCustomer($this->tenant, 'jan@example.test');

        $add = $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 2]);
        $anonToken = $add->getCookie('cart_token')->getValue();

        $response = $this->login('jan@example.test', $anonToken);

        $response->assertRedirect($this->url('/ucet'));

        $carts = $this->cartsFor($this->tenant);
        $this->assertCount(1, $carts);

        $cart = $carts->first();
        $this->assertSame($customer->id, $cart->customer_id);
        $this->assertNull($cart->converted_at);
        $this->assertSame([$product->id => 2], $this->itemsOf($this->tenant, $cart));

        // The cookie still names the very same cart — nothing to change,
        // but queueRefresh() re-affirms it rather than silently doing
        // nothing, so this pins that the response still carries it.
        $response->assertCookie('cart_token', $anonToken);
    }

    public function test_items_merge_by_summing_quantities_when_the_customer_already_has_a_cart(): void
    {
        $productX = $this->makeProduct($this->tenant, ['name' => 'Produkt X', 'price' => 10_000]);
        $productY = $this->makeProduct($this->tenant, ['name' => 'Produkt Y', 'price' => 20_000]);
        $customer = $this->makeCustomer($this->tenant, 'jan@example.test');

        // The customer's existing cart, as if seeded on a previous, already
        // signed-in visit — built directly through the repository, the same
        // way any other caller in this codebase does, not through HTTP
        // (nothing in this test exercises the storefront while signed in).
        $existingCart = $this->context->runAs($this->tenant, function () use ($customer, $productX, $productY) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);
            $carts->addItem($cart, $productX->id, 1);
            $carts->addItem($cart, $productY->id, 3);
            $carts->attachToCustomer($cart, $customer->id);

            return $cart;
        });

        // The anonymous cart from this session: product X again (to prove
        // summing, not overwriting) plus nothing else.
        $add = $this->post($this->url('/kosik'), ['product_id' => $productX->id, 'quantity' => 2]);
        $anonToken = $add->getCookie('cart_token')->getValue();
        $this->assertNotSame($existingCart->token, $anonToken);

        $response = $this->login('jan@example.test', $anonToken);
        $response->assertRedirect($this->url('/ucet'));

        $carts = $this->cartsFor($this->tenant);
        $this->assertCount(2, $carts, 'the anonymous cart is retired, not deleted');

        $merged = $carts->firstWhere('id', $existingCart->id);
        $this->assertNotNull($merged);
        $this->assertNull($merged->converted_at);
        $this->assertSame(
            [$productX->id => 3, $productY->id => 3],
            $this->itemsOf($this->tenant, $merged),
        );

        $retiredAnon = $carts->firstWhere('token', $anonToken);
        $this->assertNotNull($retiredAnon);
        $this->assertNotNull($retiredAnon->converted_at, 'the spent anonymous cart must be frozen');
        $this->assertNull($retiredAnon->customer_id);

        // The browser must now track the merged-into cart, not the retired
        // anonymous one — otherwise the next /kosik request resolves right
        // back to a dead row.
        $response->assertCookie('cart_token', $existingCart->token);
    }

    public function test_no_anonymous_cookie_leaves_the_customers_existing_cart_untouched(): void
    {
        $product = $this->makeProduct($this->tenant);
        $customer = $this->makeCustomer($this->tenant, 'jan@example.test');

        $existingCart = $this->context->runAs($this->tenant, function () use ($customer, $product) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);
            $carts->addItem($cart, $product->id, 1);
            $carts->attachToCustomer($cart, $customer->id);

            return $cart;
        });

        $response = $this->login('jan@example.test'); // no cart_token cookie sent at all
        $response->assertRedirect($this->url('/ucet'));

        $carts = $this->cartsFor($this->tenant);
        $this->assertCount(1, $carts);
        $this->assertSame($existingCart->id, $carts->first()->id);
        $this->assertSame([$product->id => 1], $this->itemsOf($this->tenant, $carts->first()));
        $this->assertNull($carts->first()->converted_at);

        // No anonymous cookie means nothing to merge, but the browser must
        // still be re-pointed at the customer's real, already-saved cart —
        // a fresh browser, a second device, or cookies cleared since that
        // cart was built must not make it unreachable. Every cart-resolving
        // controller reads the active cart solely from this cookie, with
        // no customer_id fallback.
        $response->assertCookie('cart_token', $existingCart->token);
    }

    public function test_an_empty_anonymous_cart_leaves_the_customers_existing_cart_untouched(): void
    {
        $product = $this->makeProduct($this->tenant);
        $customer = $this->makeCustomer($this->tenant, 'jan@example.test');

        $existingCart = $this->context->runAs($this->tenant, function () use ($customer, $product) {
            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);
            $carts->addItem($cart, $product->id, 1);
            $carts->attachToCustomer($cart, $customer->id);

            return $cart;
        });

        // A plain visit to /kosik mints a cookie for a brand new, empty cart
        // — nothing was ever added to it.
        $visit = $this->get($this->url('/kosik'));
        $emptyAnonToken = $visit->getCookie('cart_token')->getValue();

        $response = $this->login('jan@example.test', $emptyAnonToken);
        $response->assertRedirect($this->url('/ucet'));

        $carts = $this->cartsFor($this->tenant);
        $this->assertCount(2, $carts, 'the empty anonymous cart is untouched, not retired');

        $customerCart = $carts->firstWhere('id', $existingCart->id);
        $this->assertSame([$product->id => 1], $this->itemsOf($this->tenant, $customerCart));
        $this->assertNull($customerCart->converted_at);

        $emptyAnon = $carts->firstWhere('token', $emptyAnonToken);
        $this->assertNotNull($emptyAnon);
        $this->assertNull($emptyAnon->converted_at, 'an untouched empty cart is never retired');

        // An empty anonymous cart is nothing to merge — but the browser
        // must still end up tracking the customer's real cart, not the
        // empty one it happened to arrive with.
        $response->assertCookie('cart_token', $existingCart->token);
    }

    public function test_a_cart_cookie_token_from_another_tenant_merges_nothing_at_login(): void
    {
        $otherTenant = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['storefront', 'customers', 'checkout'] as $module) {
            $this->activateModule($otherTenant, $module);
        }

        $foreignProduct = $this->makeProduct($otherTenant, ['name' => 'Cizí produkt']);
        $add = $this->post($this->url('/kosik', $otherTenant), ['product_id' => $foreignProduct->id, 'quantity' => 5]);
        $foreignToken = $add->getCookie('cart_token')->getValue();

        $customer = $this->makeCustomer($this->tenant, 'jan@example.test');

        // Tenant B's token, replayed at tenant A's login — but Cart is
        // BelongsToTenant-scoped, so under tenant A's scope it simply does
        // not resolve. forToken() falls through to minting a fresh cart for
        // tenant A instead (the same "unresolvable token" behaviour any
        // other visit gets) — always empty, so CartMerger's own empty-cart
        // guard is what actually keeps this from attaching or merging
        // anything, the same guard the "empty anonymous cart" test above
        // exercises directly.
        $response = $this->login('jan@example.test', $foreignToken);
        $response->assertRedirect($this->url('/ucet'));

        $tenantACarts = $this->cartsFor($this->tenant);
        $this->assertCount(1, $tenantACarts, 'a fresh, empty cart for tenant A — not tenant Bs');
        $this->assertNull($tenantACarts->first()->customer_id, 'never attached: it was empty');
        $this->assertNotSame($foreignToken, $tenantACarts->first()->token);

        // Tenant B's real cart is completely untouched.
        $foreignCarts = $this->cartsFor($otherTenant);
        $this->assertCount(1, $foreignCarts);
        $this->assertNull($foreignCarts->first()->customer_id);
        $this->assertNull($foreignCarts->first()->converted_at);
        $this->assertSame([$foreignProduct->id => 5], $this->itemsOf($otherTenant, $foreignCarts->first()));
    }
}
