<?php

namespace Modules\Checkout\Http\Controllers;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Shipping\Contracts\ShippingOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Modules\Checkout\Http\Requests\ChooseShippingRequest;
use Modules\Checkout\Services\CartPricer;
use Modules\Checkout\Support\CartCookie;
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
     * Same explicit `Cache-Control: private, no-store` CartController uses —
     * no page-cache layer exists yet in this codebase to register a route
     * exclusion with (see CartController::uncached()'s own note).
     */
    private function uncached(View $view): Response
    {
        return response($view)->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
