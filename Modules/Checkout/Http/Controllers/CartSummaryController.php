<?php

namespace Modules\Checkout\Http\Controllers;

use App\Core\Checkout\Contracts\CartRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Checkout\Services\CartPricer;
use Modules\Checkout\Support\CartCookie;

/**
 * `GET /api/kosik/souhrn` — the mini-cart island's own endpoint.
 *
 * Storefront pages never bake a shopper's basket count or total into their
 * HTML (spec §15.6): a page-cache layer keyed only by tenant and path would
 * otherwise hand one visitor's cart to the next. This is the fetch the
 * header's mini-cart island makes after the surrounding page has already
 * rendered — never the page's only source of cart content, and never itself
 * a candidate for caching.
 */
class CartSummaryController
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CartPricer $pricer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));
        $priced = $this->pricer->price($cart);

        $response = response()->json([
            'count' => $priced->itemCount(),
            'items_total' => $priced->itemsTotal->amount,
            'items_total_formatted' => $priced->itemsTotal->format(),
            'currency' => $priced->itemsTotal->currency,
        ])->withHeaders(['Cache-Control' => 'private, no-store']);

        return CartCookie::attach($response, $cart, $request);
    }
}
