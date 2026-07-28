<?php

namespace Modules\Checkout\Http\Controllers;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Checkout\Contracts\CartShape;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Checkout\Http\Requests\ApplyDiscountRequest;
use Modules\Checkout\Services\CartPricer;
use Modules\Checkout\Support\CartCookie;

/**
 * `/kosik/sleva` — applying and clearing a discount code.
 *
 * POST + redirect (PRG), never a fetch: the field has to work with
 * JavaScript switched off, and a redirect is also what keeps a page refresh
 * from resubmitting the code (.claude/rules/storefront-rendering.md).
 *
 * apply() does not decide validity itself. It stores the typed code, then
 * asks CartPricer — the same call every /kosik render already makes — what
 * the cart looks like with it. That is deliberate: there is exactly one
 * place the engine is asked "does this code work", so the answer a shopper
 * gets here can never drift from what the very next render would show. A
 * rejected code is then removed again before the request ends (AK 2) — the
 * shopper is told why via the storefront's ordinary $errors/old() flash,
 * the same mechanism every other form here uses, not by leaving a dead code
 * sitting on the cart until they notice nothing changed.
 */
class CartDiscountController
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CartPricer $pricer,
    ) {}

    public function apply(ApplyDiscountRequest $request): RedirectResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));

        $code = mb_strtoupper(trim($request->string('code')->toString()));

        $this->carts->setCouponCode($cart, $code);

        $priced = $this->pricer->price($cart);

        if ($priced->discountRejection !== null) {
            $this->carts->setCouponCode($cart, null);

            // Explicit 'cs' locale: config('app.locale') is 'en' in this
            // project and translation files are not otherwise used (UI
            // strings are plain Czech literals elsewhere), so relying on the
            // app's own locale here would silently print the raw key.
            $message = 'Slevový kód neplatí — '
                .__('discounts.rejection.'.$priced->discountRejection->reason, [], 'cs');

            return $this->back($request, $cart)
                ->withErrors(['code' => $message])
                ->withInput();
        }

        return $this->back($request, $cart);
    }

    public function remove(Request $request): RedirectResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));

        $this->carts->setCouponCode($cart, null);

        return $this->back($request, $cart);
    }

    /**
     * Back to whichever screen carried the field — never to a URL from the
     * request body. `return_to` is compared against a literal, not
     * interpolated into anything, so it cannot be turned into an open
     * redirect no matter what the request actually contains.
     */
    private function back(Request $request, CartShape $cart): RedirectResponse
    {
        $route = $request->input('return_to') === 'checkout'
            ? route('storefront.checkout.details')
            : route('storefront.checkout.show');

        return CartCookie::attach(redirect()->to($route), $cart, $request);
    }
}
