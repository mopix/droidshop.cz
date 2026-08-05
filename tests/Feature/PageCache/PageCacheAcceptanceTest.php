<?php

namespace Tests\Feature\PageCache;

use App\Core\Money\Money;
use App\Core\PageCache\DynamicTokens;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Customers\Models\Customer;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Tasks 1-12 test the page-cache mechanism piece by piece. This proves the
 * three end-to-end acceptance properties spec §15.6 actually cares about,
 * on the real storefront product page rather than a purpose-built probe
 * route (PageCacheMiddlewareTest):
 *
 *  1. A visitor served someone else's cached HTML can still buy — the CSRF
 *     token in the add-to-cart form must be their own, and the item must
 *     really land in their own cart.
 *  2. A signed-in customer requesting a page an anonymous visitor already
 *     warmed must never receive the anonymous header ("Přihlásit se").
 *  3. A price change is visible on the very next request, not after the
 *     cache's TTL.
 *
 * No Product factory exists in this codebase (2026-07-28 decision: writes go
 * exclusively through ProductWriter/VariantWriter so sanitisation, slugging
 * and price history stay intact) — the same helper shape used throughout
 * PageCacheInvalidationTest and StorefrontCatalogTest.
 */
class PageCacheAcceptanceTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private Product $product;

    /** Formatted as a Money value renders it (NBSP thousands separator, cs_CZ), never a hand-typed literal. */
    private const INITIAL_PRICE = 999_00;

    private const CHANGED_PRICE = 123400;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('pagecache.enabled', true);

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();

        foreach (['storefront', 'products', 'categories', 'checkout', 'customers'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->product = $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Testovací zboží',
            'price' => self::INITIAL_PRICE,
            'status' => Product::STATUS_ACTIVE,
            'stock_tracked' => true,
            'stock_qty' => 10,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]));
    }

    private function url(string $path = ''): string
    {
        return 'http://obchod.droidshop'.$path;
    }

    private function productUrl(): string
    {
        return $this->url('/produkt/'.$this->product->slug);
    }

    public function test_a_visitor_can_buy_from_a_page_someone_else_warmed(): void
    {
        // Visitor A warms the cache.
        $this->get($this->productUrl())->assertOk();
        $this->flushSession();

        // Visitor B is served the stored HTML. The masked marker must have
        // been substituted for their own CSRF token, not visitor A's —
        // and it must not still be a naked marker either.
        $served = $this->get($this->productUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString(DynamicTokens::MARKER, $served);

        preg_match('/name="_token"\s+value="([^"]+)"/', $served, $matches);
        $this->assertNotEmpty($matches[1] ?? '', 'the served page carries no usable CSRF token');

        $add = $this->post($this->url('/kosik'), [
            '_token' => $matches[1],
            'product_id' => $this->product->id,
            'quantity' => 1,
        ]);

        // A redirect alone is weak evidence (a validation failure on this
        // route also redirects). Read back the cart token the server
        // actually issued for this POST and look the row up for real.
        $add->assertRedirect($this->url('/kosik'));
        $cartToken = $add->getCookie('cart_token')->getValue();

        $item = $this->context->runAs($this->tenant, function () use ($cartToken) {
            $cart = Cart::query()->where('token', $cartToken)->first();

            return $cart?->items()->where('product_id', $this->product->id)->first();
        });

        $this->assertNotNull($item, 'the item never reached the cart the server issued a cookie for');
        $this->assertSame(1, $item->quantity);
    }

    public function test_a_signed_in_customer_never_sees_the_anonymous_header(): void
    {
        // Anonymous visit warms the cache first — the ordering is the whole
        // point of this test. Asserting only that a signed-in visitor sees
        // "Můj účet" would prove nothing about the cache; it must be shown
        // that the page an anonymous shopper already stored does not leak
        // into the signed-in customer's own response.
        $this->get($this->productUrl())
            ->assertOk()
            ->assertSee('Přihlásit se');

        $customer = $this->context->runAs($this->tenant, fn () => Customer::factory()->create());

        $this->actingAs($customer, 'customer')
            ->get($this->productUrl())
            ->assertOk()
            ->assertSee('Můj účet')
            ->assertDontSee('Přihlásit se');
    }

    public function test_a_price_change_shows_on_the_storefront_immediately(): void
    {
        $oldPrice = (new Money(self::INITIAL_PRICE, 'CZK'))->format();
        $newPrice = (new Money(self::CHANGED_PRICE, 'CZK'))->format();

        $this->get($this->productUrl())
            ->assertOk()
            ->assertSee($oldPrice);

        $this->context->runAs(
            $this->tenant,
            fn () => app(ProductWriter::class)->update($this->product, ['price' => self::CHANGED_PRICE]),
        );

        $this->get($this->productUrl())
            ->assertOk()
            ->assertSee($newPrice)
            ->assertDontSee($oldPrice);
    }
}
