<?php

namespace Modules\Checkout\Http\Controllers;

use App\Core\Catalog\Exceptions\InsufficientStock;
use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Checkout\Contracts\CartShape;
use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\Contracts\OrderSettlement;
use App\Core\Orders\Exceptions\OrderPlacementUnavailable;
use App\Core\Orders\Exceptions\PriceChanged;
use App\Core\Orders\PlacementRequest;
use App\Core\Payments\Contracts\PaymentGatewayRegistry;
use App\Core\Payments\Exceptions\GatewayError;
use App\Core\Shipping\Contracts\PaymentOption;
use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Shipping\Contracts\ShippingOption;
use App\Core\Shipping\Contracts\ShippingOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Checkout\Http\Requests\ChooseShippingRequest;
use Modules\Checkout\Http\Requests\PlaceOrderRequest;
use Modules\Checkout\Services\CartPricer;
use Modules\Checkout\Support\CartCookie;
use Modules\Checkout\Support\PricedCart;
use Modules\Storefront\Support\Seo;

/**
 * `/pokladna/doprava` — the shipping + payment step. Same no-JS contract as
 * CartController: every mutation is a real POST that redirects to a freshly
 * server-rendered page, never a fetch the page depends on to show its own
 * contents (spec §16.3, .claude/rules/storefront-rendering.md).
 *
 * `checkout` declares no manifest `requires` on `shipping` (plan decision
 * 1): ShippingOptions/PaymentOptions are read through their kernel
 * contracts, which answer empty when the shipping module is absent or
 * deactivated for this tenant — the fallback rendered here is what "the
 * step is skipped" means for a shopper (spec fallback: personal pickup,
 * free), not a hard dependency that would make shipping undeactivatable.
 */
class CheckoutController
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CartPricer $pricer,
        private readonly ShippingOptions $shippingOptions,
        private readonly PaymentOptions $paymentOptions,
        private readonly OrderPlacement $orders,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly OrderSettlement $settlement,
    ) {}

    public function shipping(Request $request): Response|RedirectResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));
        $priced = $this->pricer->price($cart);

        if ($priced->isEmpty()) {
            return CartCookie::attach(
                redirect()->route('storefront.checkout.show')->with('status', 'Košík je prázdný.'),
                $cart,
                $request,
            );
        }

        $weightGrams = $this->pricer->weightGrams($cart);
        $available = $this->shippingOptions->available($weightGrams);
        $usingFallback = $available->isEmpty();

        $selectedShipping = null;
        $payments = new Collection;
        $selectedPayment = null;

        if (! $usingFallback) {
            $selectedShippingId = $cart->cartShippingMethodId();
            $selectedShipping = $selectedShippingId === null
                ? null
                : $available->first(fn ($option) => $option->id() === $selectedShippingId);

            if ($selectedShipping !== null) {
                $payments = $this->paymentOptions->forShipping($selectedShipping->id());

                $selectedPaymentId = $cart->cartPaymentMethodId();
                $selectedPayment = $selectedPaymentId === null
                    ? null
                    : $payments->first(fn ($option) => $option->id() === $selectedPaymentId);
            }
        }

        $shippingCost = $selectedShipping !== null
            ? $this->pricer->shippingCost($priced->itemsTotal, $selectedShipping)
            : null;

        $paymentFee = $selectedPayment?->fee();

        $total = $priced->itemsTotal;

        if ($shippingCost !== null) {
            $total = $total->plus($shippingCost);
        }

        if ($paymentFee !== null) {
            $total = $total->plus($paymentFee);
        }

        $view = view('checkout::checkout.shipping', [
            'cart' => $priced,
            'usingFallback' => $usingFallback,
            'shippingOptions' => $available,
            'selectedShipping' => $selectedShipping,
            'paymentOptions' => $payments,
            'selectedPayment' => $selectedPayment,
            'shippingCost' => $shippingCost,
            'total' => $total,
            'seo' => new Seo(title: 'Doprava a platba', noindex: true),
        ]);

        return CartCookie::attach($this->uncached($view), $cart, $request);
    }

    public function chooseShipping(ChooseShippingRequest $request): RedirectResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));

        // Validated by ChooseShippingRequest against ShippingOptions /
        // PaymentOptions already — nothing here re-derives a price from
        // this data, only ids to look the options up again on the next
        // render (AK 5, AK 10).
        $shippingId = $request->filled('shipping_method_id') ? $request->integer('shipping_method_id') : null;
        $paymentId = $request->filled('payment_method_id') ? $request->integer('payment_method_id') : null;

        $this->carts->chooseShipping($cart, $shippingId, $paymentId);

        return CartCookie::attach(
            redirect()->route('storefront.checkout.shipping'),
            $cart,
            $request,
        );
    }

    /**
     * `/pokladna/udaje` — the contact/address form plus a server-rendered
     * recap (line items, delivery, payment, VAT breakdown, total) and the
     * "Objednat s povinností platby" button.
     *
     * The recap is built entirely from the priced cart and the chosen options,
     * read fresh — never from anything a client posted (AK 5). The hidden
     * checkout_token minted here is the idempotency key the form posts back
     * (AK 2).
     */
    public function details(Request $request): Response|RedirectResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));
        $priced = $this->pricer->price($cart);

        if ($priced->isEmpty()) {
            return CartCookie::attach(
                redirect()->route('storefront.checkout.show')->with('status', 'Košík je prázdný.'),
                $cart,
                $request,
            );
        }

        $selection = $this->resolveSelection($cart, $priced);

        // Shipping options exist but none is chosen yet: send the shopper to
        // the step that picks one, so the recap they confirm is complete. The
        // free-pickup fallback (shipping module off) has no such step and is
        // left to place straight through (Task 5 open question).
        if (! $selection['usingFallback'] && $selection['shipping'] === null) {
            return CartCookie::attach(
                redirect()->route('storefront.checkout.shipping'),
                $cart,
                $request,
            );
        }

        $view = view('checkout::checkout.details', [
            'cart' => $priced,
            'usingFallback' => $selection['usingFallback'],
            'shipping' => $selection['shipping'],
            'shippingCost' => $selection['shippingCost'],
            'payment' => $selection['payment'],
            'paymentFee' => $selection['paymentFee'],
            'total' => $selection['total'],
            'vatBreakdown' => $this->pricer->vatBreakdown(
                $priced,
                $selection['shipping'],
                $selection['shippingCost'],
                $selection['payment'],
                $selection['paymentFee'],
            ),
            'checkoutToken' => Str::random(40),
            'seo' => new Seo(title: 'Údaje a rekapitulace', noindex: true),
        ]);

        return CartCookie::attach($this->uncached($view), $cart, $request);
    }

    /**
     * POST `/pokladna/udaje` — validate, then hand the cart and the shopper's
     * choices to OrderPlacement::place(). No price is recomputed here:
     * OrderPlacer is the single pricing authority (AK 5), and the chosen
     * shipping/payment ids come from the cart's stored selection, never the
     * POST body.
     *
     * The checkout_token from the hidden field is the idempotency key, so a
     * double submit of the same form returns the one order already placed
     * (AK 2). Mail is not sent from here: OrderPlacer fires OrderPlaced after
     * its commit and the orders module's listener sends the confirmations —
     * so a resubmit sends nothing extra and nothing is queued inside the
     * placement transaction.
     */
    public function place(PlaceOrderRequest $request): RedirectResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));
        $priced = $this->pricer->price($cart);

        if ($priced->isEmpty()) {
            return CartCookie::attach(
                redirect()->route('storefront.checkout.show')->with('status', 'Košík je prázdný.'),
                $cart,
                $request,
            );
        }

        $placement = new PlacementRequest(
            cart: $cart,
            // The cart's own stored selection — validated when it was chosen on
            // the shipping step. Null (fallback / step skipped) is fine:
            // OrderPlacer treats it as free personal pickup.
            shippingMethodId: $cart->cartShippingMethodId(),
            paymentMethodId: $cart->cartPaymentMethodId(),
            email: (string) $request->string('email'),
            phone: (string) $request->string('phone'),
            billing: $request->billingAddress(),
            shipping: $request->deliveryAddress(),
            checkoutToken: (string) $request->string('checkout_token'),
            customerId: Auth::guard('customer')->id(),
            source: 'storefront',
            note: $request->filled('note') ? (string) $request->string('note') : null,
        );

        try {
            $placed = $this->orders->place($placement);
        } catch (PriceChanged $e) {
            // Old = the figure the shopper agreed to in the cart, new = what
            // the catalogue charges now (AK 4). Sent back to the cart, where
            // the per-line banner already explains the change too.
            return CartCookie::attach(
                redirect()->route('storefront.checkout.show')->with(
                    'status',
                    'Cena se u některé položky změnila z '.$e->oldPrice->format().' na '.$e->newPrice->format().'. Zkontrolujte prosím košík a objednávku dokončete znovu.',
                ),
                $cart,
                $request,
            );
        } catch (InsufficientStock $e) {
            // The last unit went between adding it and submitting (AK 3).
            return CartCookie::attach(
                redirect()->route('storefront.checkout.show')->with(
                    'status',
                    'Litujeme, poslední kus některé položky byl právě vyprodán. Upravte prosím košík.',
                ),
                $cart,
                $request,
            );
        } catch (OrderPlacementUnavailable $e) {
            // The orders module is off for this shop: it cannot take orders
            // right now. Nothing was written.
            return CartCookie::attach(
                back()->with('status', 'E-shop momentálně nepřijímá objednávky. Zkuste to prosím později.'),
                $cart,
                $request,
            );
        }

        // Placed. If the chosen method is an online gateway this shop actually
        // runs, start the payment and send the shopper to the gateway; the
        // order stays unpaid until a verified callback settles it. Otherwise
        // (offline method, or no gateway configured) go straight to the
        // thank-you page. The registry, not the order, decides which providers
        // are online: for('cod') and for('bank_transfer') resolve to null, so
        // those fall through here. Confirmation mail is already on its way,
        // sent by the orders module's OrderPlaced listener.
        $provider = $placed->paymentProvider();
        $gateway = $provider !== null ? $this->gateways->for($provider) : null;

        if ($gateway !== null) {
            try {
                $initiation = $gateway->initiate($placed->uuid());
            } catch (GatewayError $e) {
                // The order exists and is unpaid; we simply could not start the
                // payment. Keep the order and route the shopper to its
                // confirmation so they can retry, rather than losing the sale.
                // A delayed expiry job (where a queue runs) returns the stock
                // if it is never paid.
                return CartCookie::forget(
                    redirect()->route('storefront.checkout.thankYou', ['uuid' => $placed->uuid()])
                        ->with('status', 'Objednávku jsme přijali, ale platbu se nepodařilo zahájit. Zkuste ji prosím dokončit znovu.'),
                    $request,
                );
            }

            // Bind the gateway transaction to the order server-side, so the
            // return and webhook re-verify THIS reference and never one handed
            // to them in a request (spec §16.6).
            $this->settlement->attachReference($placed->uuid(), $initiation->reference());

            return CartCookie::forget(redirect()->away($initiation->redirectUrl()), $request);
        }

        return CartCookie::forget(
            redirect()->route('storefront.checkout.thankYou', ['uuid' => $placed->uuid()]),
            $request,
        );
    }

    /**
     * Resolves the cart's chosen shipping/payment into display shapes and
     * server-computed costs, or the free personal-pickup fallback when the
     * shipping module is off (plan decision 1). Mirrors shipping()'s own
     * resolution, kept separate so the details recap never re-derives a price
     * from anything but the options themselves (AK 5, AK 10).
     *
     * @return array{usingFallback: bool, shipping: ?ShippingOption, shippingCost: Money, payment: ?PaymentOption, paymentFee: Money, total: Money}
     */
    private function resolveSelection(CartShape $cart, PricedCart $priced): array
    {
        $weightGrams = $this->pricer->weightGrams($cart);
        $available = $this->shippingOptions->available($weightGrams);
        $usingFallback = $available->isEmpty();

        $currency = $priced->itemsTotal->currency;

        $shipping = null;
        $payment = null;

        if (! $usingFallback) {
            $selectedShippingId = $cart->cartShippingMethodId();
            $shipping = $selectedShippingId === null
                ? null
                : $available->first(fn (ShippingOption $option) => $option->id() === $selectedShippingId);

            if ($shipping !== null) {
                $payments = $this->paymentOptions->forShipping($shipping->id());
                $selectedPaymentId = $cart->cartPaymentMethodId();
                $payment = $selectedPaymentId === null
                    ? null
                    : $payments->first(fn (PaymentOption $option) => $option->id() === $selectedPaymentId);
            }
        }

        $shippingCost = $shipping !== null
            ? $this->pricer->shippingCost($priced->itemsTotal, $shipping)
            : new Money(0, $currency);

        $paymentFee = $payment?->fee() ?? new Money(0, $currency);

        $total = $priced->itemsTotal->plus($shippingCost)->plus($paymentFee);

        return [
            'usingFallback' => $usingFallback,
            'shipping' => $shipping,
            'shippingCost' => $shippingCost,
            'payment' => $payment,
            'paymentFee' => $paymentFee,
            'total' => $total,
        ];
    }

    /**
     * Same explicit `Cache-Control: private, no-store` CartController uses —
     * no page-cache layer exists yet in this codebase to register a route
     * exclusion with (see CartController::uncached()'s own note).
     */
    private function uncached(View $view): Response
    {
        return response($view)->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
