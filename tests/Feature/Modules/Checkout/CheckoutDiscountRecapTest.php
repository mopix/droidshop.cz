<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Money\Money;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Discounts\Models\Discount;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * `/pokladna/udaje` — the recap on the page carrying "Objednat s povinností
 * platby" must show a discount as its own row and the displayed components
 * must reconcile with the displayed total (final review of Task 5 flagged
 * that the recap used to show a pre-discount "Mezisoučet" next to a total
 * computed from the post-discount payableTotal, with no line explaining the
 * gap — spec §16.3, .claude/rules/storefront-rendering.md).
 */
class CheckoutDiscountRecapTest extends TestCase
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

        foreach (['storefront', 'checkout', 'products', 'categories', 'orders', 'shipping', 'discounts'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    /**
     * Money::format() uses NumberFormatter's own grouping and non-breaking
     * spaces, so assertions render the expectation through the exact same
     * formatter rather than guessing the literal bytes (mirrors
     * CheckoutShippingTest/CartDiscountFormTest).
     */
    private function czk(int $minorUnits): string
    {
        return (new Money($minorUnits, 'CZK'))->format();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(array $attributes = []): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Testovací produkt',
            'price' => 100_000, // 1 000,00 Kč
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'stock_qty' => 10,
            ...$attributes,
        ]));
    }

    private function cartToken(): string
    {
        return $this->context->runAs($this->tenant, fn () => Cart::query()->firstOrFail()->token);
    }

    public function test_the_checkout_recap_shows_the_discount_and_the_reduced_total(): void
    {
        $product = $this->context->runAs($this->tenant, function (): Product {
            $product = $this->makeProduct();

            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            return $product;
        });

        $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertRedirect();

        $token = $this->cartToken();

        $this->withCookie('cart_token', $token)
            ->post($this->url('/kosik/sleva'), ['code' => 'SLEVA10', 'return_to' => 'checkout'])
            ->assertRedirect($this->url('/pokladna/udaje'));

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/udaje'));

        $page->assertOk();
        $page->assertSee('Sleva 10 %');
        $page->assertSee($this->czk(90_000), false); // 1 000,00 − 10 % = 900,00 Kč
    }

    /**
     * Pins the recap arithmetic: the subtotal, the discount, the shipping
     * cost and the payment fee shown on the page must sum to the total shown
     * next to the submit button — not merely each be individually correct.
     * Every figure here is non-zero so a defect in any one row would move
     * the sum away from the asserted total.
     */
    public function test_the_recap_rows_reconcile_with_the_displayed_total(): void
    {
        $product = $this->context->runAs($this->tenant, function (): Product {
            $product = $this->makeProduct(['price' => 100_000]); // 1 000,00 Kč

            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']); // 10 %

            ShippingMethod::create([
                'provider' => ShippingMethod::PROVIDER_FLAT,
                'name' => 'Kurýr',
                'price' => 9_900, // 99,00 Kč
                'is_active' => true,
            ]);

            PaymentMethod::create([
                'provider' => PaymentMethod::PROVIDER_COD,
                'name' => 'Dobírka',
                'fee' => 2_000, // 20,00 Kč
                'is_active' => true,
            ]);

            return $product;
        });

        $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
        $token = $this->cartToken();

        $this->withCookie('cart_token', $token)
            ->post($this->url('/kosik/sleva'), ['code' => 'SLEVA10', 'return_to' => 'checkout']);

        $shippingId = $this->context->runAs($this->tenant, fn () => ShippingMethod::query()->firstOrFail()->id);
        $paymentId = $this->context->runAs($this->tenant, fn () => PaymentMethod::query()->firstOrFail()->id);

        $this->withCookie('cart_token', $token)->post($this->url('/pokladna/doprava'), [
            'shipping_method_id' => $shippingId,
            'payment_method_id' => $paymentId,
        ]);

        $page = $this->withCookie('cart_token', $token)->get($this->url('/pokladna/udaje'));

        $page->assertOk();

        // Mezisoučet 1 000,00 − Sleva 100,00 + Doprava 99,00 + Platba 20,00 = Celkem 1 019,00 Kč.
        $subtotal = new Money(100_000, 'CZK');
        $discount = new Money(10_000, 'CZK');
        $shippingCost = new Money(9_900, 'CZK');
        $paymentFee = new Money(2_000, 'CZK');
        $total = $subtotal->minus($discount)->plus($shippingCost)->plus($paymentFee);

        $this->assertSame(101_900, $total->amount);

        $page->assertSee($subtotal->format(), false);
        $page->assertSee('−'.$discount->format(), false);
        $page->assertSee($shippingCost->format(), false);
        $page->assertSee($paymentFee->format(), false);
        $page->assertSee($total->format(), false);
    }

    public function test_the_discount_field_round_trips_back_to_the_checkout_page(): void
    {
        $product = $this->context->runAs($this->tenant, function (): Product {
            $product = $this->makeProduct();

            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            return $product;
        });

        $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => 1]);
        $token = $this->cartToken();

        $apply = $this->withCookie('cart_token', $token)
            ->post($this->url('/kosik/sleva'), ['code' => 'SLEVA10', 'return_to' => 'checkout']);

        $apply->assertRedirect($this->url('/pokladna/udaje'));

        $remove = $this->withCookie('cart_token', $token)
            ->post($this->url('/kosik/sleva/zrusit'), ['return_to' => 'checkout']);

        $remove->assertRedirect($this->url('/pokladna/udaje'));
    }
}
