<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Mail\MailKind;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Checkout\Models\Cart;
use Modules\Orders\Mail\OrderPlacedCustomer;
use Modules\Orders\Mail\OrderPlacedTenant;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * `/pokladna/udaje` → submission → `/dekujeme/{uuid}`, driven the way a
 * shopper with JavaScript switched off would (spec §16.3,
 * .claude/rules/storefront-rendering.md): real HTTP form submits, the hidden
 * checkout_token carrying idempotency, all pricing server-side.
 *
 * This is the end-to-end acceptance flow: cart → a real order.
 */
class PlaceOrderTest extends TestCase
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

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create([
            'name' => 'Shop One',
            // A real reply-to address so the tenant confirmation has somewhere
            // to go — the shop owner's own inbox for new orders.
            'mail_reply_to' => 'shop@example.cz',
        ]);

        foreach (['storefront', 'checkout'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    // --- helpers ----------------------------------------------------------

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    private function rateId(): int
    {
        return app(TaxRates::class)->default()->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(array $attributes = []): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'price' => 100_000, // 1 000,00 Kč
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => $this->rateId(),
            'weight_g' => 200,
            ...$attributes,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeShipping(array $attributes = []): ShippingMethod
    {
        return $this->context->runAs($this->tenant, fn () => ShippingMethod::create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9_900,
            'tax_rate_id' => $this->rateId(),
            'is_active' => true,
            ...$attributes,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makePayment(array $attributes = []): PaymentMethod
    {
        return $this->context->runAs($this->tenant, fn () => PaymentMethod::create([
            'provider' => PaymentMethod::PROVIDER_COD,
            'name' => 'Dobírka',
            'fee' => 0,
            'tax_rate_id' => $this->rateId(),
            'is_active' => true,
            ...$attributes,
        ]));
    }

    private function addToCart(Product $product, int $quantity = 1): TestResponse
    {
        return $this->post($this->url('/kosik'), ['product_id' => $product->id, 'quantity' => $quantity]);
    }

    private function cartToken(): string
    {
        return $this->context->runAs($this->tenant, fn () => Cart::query()->latest('id')->firstOrFail()->token);
    }

    /**
     * Builds a persisted cart directly through the repository, with an
     * optional shipping/payment choice already made.
     *
     * Used wherever a test needs two genuinely independent carts: the Laravel
     * test HTTP client keeps a cookie jar across requests, so a second
     * `addToCart()` without an explicit cookie would just bleed into the first
     * cart. Building carts here and driving each request with an explicit
     * withCookie(token) keeps them apart.
     *
     * @param  array<int, int>  $lines  productId => quantity
     */
    private function repoCart(array $lines, ?int $shippingId = null, ?int $paymentId = null): Cart
    {
        return $this->context->runAs($this->tenant, function () use ($lines, $shippingId, $paymentId) {
            /** @var Cart $cart */
            $cart = app(CartRepository::class)->forToken(null);

            foreach ($lines as $productId => $quantity) {
                app(CartRepository::class)->addItem($cart, $productId, $quantity);
            }

            if ($shippingId !== null) {
                app(CartRepository::class)->chooseShipping($cart, $shippingId, $paymentId);
            }

            return $cart;
        });
    }

    private function chooseShippingAndPayment(string $token, int $shippingId, ?int $paymentId = null): void
    {
        $this->withCookie('cart_token', $token)->post($this->url('/pokladna/doprava'), array_filter([
            'shipping_method_id' => $shippingId,
            'payment_method_id' => $paymentId,
        ], fn ($v) => $v !== null));
    }

    /**
     * Reads the hidden idempotency token the server embedded in the details
     * form — the same value a browser would post back, which is what makes a
     * double submit collapse to one order (AK 2).
     */
    private function checkoutToken(string $cartToken): string
    {
        $page = $this->withCookie('cart_token', $cartToken)->get($this->url('/pokladna/udaje'));
        $page->assertOk();

        preg_match('/name="checkout_token"\s+value="([^"]+)"/', $page->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'The details form must embed a hidden checkout_token.');

        return $m[1];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function detailsPayload(string $checkoutToken, array $overrides = []): array
    {
        return [
            'checkout_token' => $checkoutToken,
            'email' => 'jana@example.cz',
            'phone' => '+420777123456',
            'name' => 'Jana Nováková',
            'street' => 'Hlavní 1',
            'city' => 'Praha',
            'zip' => '11000',
            'country' => 'CZ',
            'terms' => '1',
            ...$overrides,
        ];
    }

    // --- scenarios --------------------------------------------------------

    /**
     * AK 1 — the whole no-JS walk from a cart to the thank-you page places
     * exactly one real order.
     */
    public function test_a_full_walk_from_cart_to_thank_you_creates_one_order(): void
    {
        $this->activateModule($this->tenant, 'shipping');
        $this->activateModule($this->tenant, 'orders');

        $product = $this->makeProduct(['price' => 100_000]);
        $shipping = $this->makeShipping(['price' => 9_900]);
        $payment = $this->makePayment(['fee' => 0]);

        $this->addToCart($product);
        $token = $this->cartToken();
        $this->chooseShippingAndPayment($token, $shipping->id, $payment->id);

        $checkoutToken = $this->checkoutToken($token);

        $response = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($checkoutToken));

        $order = $this->context->runAs($this->tenant, fn () => Order::query()->firstOrFail());

        $response->assertRedirect($this->url('/dekujeme/'.$order->uuid));
        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
        $this->assertSame(109_900, $order->total->amount);

        // The thank-you page renders server-side and confirms the number.
        $thankYou = $this->withCookie('cart_token', $token)->get($this->url('/dekujeme/'.$order->uuid));
        $thankYou->assertOk();
        $thankYou->assertSee($order->number);
        $thankYou->assertSee('<meta name="robots" content="noindex', false);
        $header = $thankYou->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', (string) $header);
    }

    /**
     * AK 2 — a double submit of the same details form (same hidden
     * checkout_token) yields ONE order, not two.
     */
    public function test_a_double_submit_with_the_same_token_creates_one_order(): void
    {
        $this->activateModule($this->tenant, 'shipping');
        $this->activateModule($this->tenant, 'orders');

        $product = $this->makeProduct(['price' => 100_000]);
        $shipping = $this->makeShipping();
        $payment = $this->makePayment();

        $this->addToCart($product);
        $token = $this->cartToken();
        $this->chooseShippingAndPayment($token, $shipping->id, $payment->id);

        $checkoutToken = $this->checkoutToken($token);
        $payload = $this->detailsPayload($checkoutToken);

        $first = $this->withCookie('cart_token', $token)->post($this->url('/pokladna/udaje'), $payload);
        $second = $this->withCookie('cart_token', $token)->post($this->url('/pokladna/udaje'), $payload);

        $order = $this->context->runAs($this->tenant, fn () => Order::query()->firstOrFail());

        $first->assertRedirect($this->url('/dekujeme/'.$order->uuid));
        $second->assertRedirect($this->url('/dekujeme/'.$order->uuid));
        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
    }

    /**
     * AK 3 — the concurrency case surfaces as a message, not a second order:
     * a checkout on a unit that sold out between add and submit is turned
     * into the `/kosik` redirect, and nothing is written.
     */
    public function test_a_sold_out_last_unit_redirects_to_the_cart_without_a_second_order(): void
    {
        $this->activateModule($this->tenant, 'shipping');
        $this->activateModule($this->tenant, 'orders');

        $product = $this->makeProduct([
            'price' => 40_000,
            'stock_tracked' => true,
            'stock_qty' => 1,
            'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
        ]);
        $shipping = $this->makeShipping();
        $payment = $this->makePayment();

        // Two genuinely independent carts, each holding the single unit.
        $cartA = $this->repoCart([$product->id => 1], $shipping->id, $payment->id);
        $cartB = $this->repoCart([$product->id => 1], $shipping->id, $payment->id);

        // A places first, taking the unit and depleting stock to 0.
        $checkoutTokenA = $this->checkoutToken($cartA->token);
        $this->withCookie('cart_token', $cartA->token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($checkoutTokenA));

        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => Order::query()->count()));

        // B now finds nothing left: redirected back to the cart, no 2nd order.
        $checkoutTokenB = $this->checkoutToken($cartB->token);
        $bPlace = $this->withCookie('cart_token', $cartB->token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($checkoutTokenB));

        $bPlace->assertRedirect($this->url('/kosik'));
        $bPlace->assertSessionHas('status');
        $this->assertSame(1, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
    }

    /**
     * AK 4 — a price moved between the cart and submit: redirect to `/kosik`
     * with a banner naming the old and new figure, no order created.
     */
    public function test_a_price_change_between_cart_and_submit_redirects_to_the_cart_with_a_banner(): void
    {
        $this->activateModule($this->tenant, 'shipping');
        $this->activateModule($this->tenant, 'orders');

        $product = $this->makeProduct(['price' => 50_000]);
        $shipping = $this->makeShipping();
        $payment = $this->makePayment();

        $this->addToCart($product);
        $token = $this->cartToken();
        $this->chooseShippingAndPayment($token, $shipping->id, $payment->id);
        $checkoutToken = $this->checkoutToken($token);

        // The shop owner raises the price after it was snapshotted into the cart.
        $this->context->runAs($this->tenant, fn () => Product::query()->whereKey($product->id)->update(['price' => 55_000]));

        $response = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($checkoutToken));

        $response->assertRedirect($this->url('/kosik'));
        $response->assertSessionHas('status');
        $status = session('status');
        $this->assertStringContainsString('500', (string) $status); // old, 500,00
        $this->assertStringContainsString('550', (string) $status); // new, 550,00
        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Order::query()->count()));
    }

    /**
     * The confirmation e-mails (customer + tenant) are queued as transactional
     * mail — never blocked by an exhausted quota (product decision, MailKind).
     */
    public function test_confirmation_emails_are_queued_as_transactional(): void
    {
        Mail::fake();

        $this->activateModule($this->tenant, 'shipping');
        $this->activateModule($this->tenant, 'orders');

        $product = $this->makeProduct();
        $shipping = $this->makeShipping();
        $payment = $this->makePayment();

        $this->addToCart($product);
        $token = $this->cartToken();
        $this->chooseShippingAndPayment($token, $shipping->id, $payment->id);
        $checkoutToken = $this->checkoutToken($token);

        $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($checkoutToken));

        Mail::assertSent(OrderPlacedCustomer::class);
        Mail::assertSent(OrderPlacedTenant::class);

        // Both were logged as transactional against the tenant.
        $messages = MailMessage::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get();
        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            $this->assertSame(MailKind::Transactional, $message->kind);
        }
    }

    /**
     * A bank-transfer order shows a SPAYD QR on the thank-you page; a
     * cash-on-delivery one does not (there is nothing to pay in advance).
     */
    public function test_the_thank_you_page_renders_a_qr_for_bank_transfer_only(): void
    {
        $this->activateModule($this->tenant, 'shipping');
        $this->activateModule($this->tenant, 'orders');

        $product = $this->makeProduct();
        $shipping = $this->makeShipping();
        $bank = $this->makePayment([
            'name' => 'Bankovní převod',
            'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
            'fee' => 0,
            'settings' => ['account' => 'CZ6508000000192000145399'],
        ]);
        $cod = $this->makePayment(['name' => 'Dobírka', 'fee' => 0]);

        // Bank-transfer order: a QR is rendered.
        $bankCart = $this->repoCart([$product->id => 1], $shipping->id, $bank->id);
        $bankCheckoutToken = $this->checkoutToken($bankCart->token);
        $this->withCookie('cart_token', $bankCart->token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($bankCheckoutToken));

        $order = $this->context->runAs($this->tenant, fn () => Order::query()->firstOrFail());

        $bankThankYou = $this->get($this->url('/dekujeme/'.$order->uuid));
        $bankThankYou->assertOk();
        $bankThankYou->assertSee('<svg', false);
        $bankThankYou->assertSee('CZ6508000000192000145399');

        // A COD order has no QR — there is nothing to pay in advance.
        $codCart = $this->repoCart([$product->id => 1], $shipping->id, $cod->id);
        $codCheckoutToken = $this->checkoutToken($codCart->token);
        $this->withCookie('cart_token', $codCart->token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($codCheckoutToken));

        $codOrder = $this->context->runAs($this->tenant, fn () => Order::query()->latest('id')->firstOrFail());
        $codThankYou = $this->get($this->url('/dekujeme/'.$codOrder->uuid));
        $codThankYou->assertOk();
        $codThankYou->assertDontSee('<svg', false);
    }

    /**
     * The thank-you page is resolved strictly by uuid and tenant-scoped: a
     * guessed or foreign uuid reveals nothing (AK 6, 7 — leak guard).
     */
    public function test_the_thank_you_page_does_not_leak_a_foreign_or_guessed_uuid(): void
    {
        $this->activateModule($this->tenant, 'orders');

        // A guessed uuid that matches no order at all.
        $this->get($this->url('/dekujeme/'.Str::uuid()))->assertNotFound();

        // An order that genuinely exists — but for a different tenant.
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        foreach (['storefront', 'checkout', 'shipping', 'orders'] as $module) {
            $this->activateModule($other, $module);
        }

        $order = $this->context->runAs($other, fn () => Order::query()->create([
            'number' => '9001',
            'checkout_token' => 'tok-foreign',
            'email' => 'someone@else.cz',
            'billing' => ['name' => 'Někdo Jiný'],
            'items_total' => 10_000,
            'total' => 10_000,
            'currency' => 'CZK',
            'placed_at' => now(),
        ]));

        // Asked for on THIS tenant's host, the foreign order is not found.
        $response = $this->get($this->url('/dekujeme/'.$order->uuid));
        $response->assertNotFound();
        $response->assertDontSee('someone@else.cz');
    }

    /**
     * Task 5 open question: a cart with no shipping method chosen (shipping
     * module off, or the step skipped) still places, on the built-in free
     * personal-pickup fallback — shipping_total 0, order not rejected.
     */
    public function test_a_cart_without_a_shipping_method_places_on_the_free_pickup_fallback(): void
    {
        // Shipping module deliberately NOT activated for this tenant.
        $this->activateModule($this->tenant, 'orders');

        $product = $this->makeProduct(['price' => 100_000]);

        $this->addToCart($product);
        $token = $this->cartToken();
        $checkoutToken = $this->checkoutToken($token);

        $response = $this->withCookie('cart_token', $token)
            ->post($this->url('/pokladna/udaje'), $this->detailsPayload($checkoutToken));

        $order = $this->context->runAs($this->tenant, fn () => Order::query()->firstOrFail());

        $response->assertRedirect($this->url('/dekujeme/'.$order->uuid));
        $this->assertSame(0, $order->shipping_total->amount);
        $this->assertSame(100_000, $order->total->amount);
    }
}
