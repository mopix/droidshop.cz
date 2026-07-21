<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Money\Money;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The `/kosik` storefront page, driven the way a shopper without JavaScript
 * would: real HTTP form submits (POST/PATCH/DELETE), never the API. Every
 * price asserted here must come from the raw HTML the server produced, not
 * from a follow-up request — that is the whole point of the SSR rule this
 * page exists to prove out (spec §16.3, .claude/rules/storefront-rendering.md).
 */
class CartPageTest extends TestCase
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

        foreach (['storefront', 'checkout'] as $module) {
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
    private function makeProduct(array $attributes = []): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 59_900,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            ...$attributes,
        ]));
    }

    private function cartTokenInDb(): string
    {
        return $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail()->token);
    }

    /**
     * Money::format() uses NumberFormatter's own grouping and non-breaking
     * spaces (cs_CZ groups digits with U+00A0, not an ASCII space), so
     * assertions render the expectation through the exact same formatter
     * rather than guessing the literal bytes.
     */
    private function czk(int $minorUnits): string
    {
        return (new Money($minorUnits, 'CZK'))->format();
    }

    public function test_adding_a_product_creates_a_cart_and_the_line_is_visible_in_the_raw_html(): void
    {
        $product = $this->makeProduct(['price' => 59_900]);

        $add = $this->post($this->url('/kosik'), [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $add->assertRedirect($this->url('/kosik'));
        $add->assertCookie('cart_token');

        $cart = $this->context->runAs($this->tenant, fn () => Cart::query()->first());
        $this->assertNotNull($cart);
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(2, $cart->items()->first()->quantity);
        $this->assertSame(59_900, $cart->items()->first()->unit_price->amount);

        $page = $this->withCookie('cart_token', $cart->token)
            ->get($this->url('/kosik'));

        $page->assertOk();
        // The product name and the line total (599,00 Kč x 2 = 1 198,00 Kč)
        // must be in the server's first response — no fetch, no hydration.
        $page->assertSee('Klávesnice Acme');
        $page->assertSee($this->czk(119_800));
    }

    public function test_increasing_quantity_and_removing_a_line_both_work_without_javascript(): void
    {
        $product = $this->makeProduct(['price' => 10_000]);

        $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
        $token = $this->cartTokenInDb();
        $item = $this->context->runAs($this->tenant, fn () => Cart::query()->first()->items()->first());

        // "+": a real PATCH via a form's _method spoof, exactly what a Blade
        // form without JS submits.
        $increase = $this->withCookie('cart_token', $token)
            ->patch($this->url('/kosik/'.$item->id), ['quantity' => 3]);

        $increase->assertRedirect($this->url('/kosik'));
        $this->assertSame(3, $this->context->runAs($this->tenant, fn () => $item->fresh()->quantity));

        $afterIncrease = $this->withCookie('cart_token', $token)->get($this->url('/kosik'));
        $afterIncrease->assertSee($this->czk(30_000)); // 100,00 Kč x 3

        // Remove entirely via a real DELETE.
        $remove = $this->withCookie('cart_token', $token)
            ->delete($this->url('/kosik/'.$item->id));

        $remove->assertRedirect($this->url('/kosik'));
        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);

        $emptyPage = $this->withCookie('cart_token', $token)->get($this->url('/kosik'));
        $emptyPage->assertOk();
        $emptyPage->assertSee('prázdný');
    }

    public function test_a_spoofed_price_in_the_post_body_is_ignored_the_catalog_price_is_charged(): void
    {
        $product = $this->makeProduct(['price' => 100_000]); // 1 000,00 Kč

        $this->post($this->url('/kosik'), [
            'product_id' => $product->id,
            'quantity' => 1,
            // A shopper's browser dev tools could add this; it must never
            // reach the pricing authority (AK 5).
            'price' => 1,
            'unit_price' => 1,
        ]);

        $cart = $this->context->runAs($this->tenant, fn () => Cart::query()->first());
        $item = $cart->items()->first();

        $this->assertSame(100_000, $item->unit_price->amount);

        $page = $this->withCookie('cart_token', $cart->token)->get($this->url('/kosik'));
        $page->assertSee($this->czk(100_000));
        $page->assertDontSee($this->czk(1));
    }

    public function test_a_price_change_since_adding_shows_a_banner_and_recomputes_the_total(): void
    {
        $product = $this->makeProduct(['price' => 100_000]); // 1 000,00 Kč

        $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
        $token = $this->cartTokenInDb();

        // The catalogue price moves after the item was snapshotted into the
        // cart — the classic AK 4 scenario.
        $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->update($product, ['price' => 150_000]));

        $page = $this->withCookie('cart_token', $token)->get($this->url('/kosik'));

        $page->assertOk();
        $page->assertSee($this->czk(100_000)); // old price, named in the banner
        $page->assertSee($this->czk(150_000)); // new price, both in the banner and the recomputed total
    }

    public function test_a_cart_cookie_token_from_another_tenant_resolves_to_nothing(): void
    {
        $otherTenant = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        $this->activateModule($otherTenant, 'checkout');
        $this->activateModule($otherTenant, 'storefront');

        $product = $this->makeProduct(['price' => 10_000]);
        $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
        $tokenA = $this->cartTokenInDb();

        // Tenant A's own token, replayed against tenant B's host. carts is
        // tenant-scoped by (tenant_id, token), so this must never surface
        // tenant A's line item on tenant B's page (AK 6).
        $page = $this->withCookie('cart_token', $tokenA)
            ->get($this->url('/kosik', $otherTenant));

        $page->assertOk();
        $page->assertDontSee('Klávesnice Acme');
        $page->assertSee('prázdný');

        $cartsForB = $this->context->runAs($otherTenant, fn () => Cart::query()->count());
        // A fresh cart was minted for tenant B; tenant A's row was untouched.
        $this->assertSame(1, $cartsForB);
        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => Cart::query()->count()));
    }

    public function test_the_mini_cart_summary_endpoint_is_never_cached(): void
    {
        $response = $this->get($this->url('/api/kosik/souhrn'));

        $response->assertOk();
        $this->assertCacheControlRefusesCaching($response->headers->get('Cache-Control'));
    }

    public function test_the_cart_page_itself_refuses_caching(): void
    {
        $response = $this->get($this->url('/kosik'));

        $response->assertOk();
        $this->assertCacheControlRefusesCaching($response->headers->get('Cache-Control'));
        $response->assertSee('<meta name="robots" content="noindex', false);
    }

    /**
     * Symfony's Response::prepare() rebuilds Cache-Control from its own
     * directive bag and emits it alphabetically ("no-store, private"), not
     * in whatever order the controller wrote it — so this checks both
     * directives are present rather than pinning an exact string.
     */
    private function assertCacheControlRefusesCaching(?string $header): void
    {
        $this->assertNotNull($header);
        $this->assertStringContainsString('private', $header);
        $this->assertStringContainsString('no-store', $header);
    }
}
