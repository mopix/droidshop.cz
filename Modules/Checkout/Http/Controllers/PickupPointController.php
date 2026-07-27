<?php

namespace Modules\Checkout\Http\Controllers;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Shipping\Contracts\PickupPointCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Checkout\Support\CartCookie;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Storefront\Support\Seo;

/**
 * `/pokladna/vydejni-misto` — choosing a Zásilkovna pickup point, server
 * rendered (wave 2.5). This is the primary path, not a fallback: the whole
 * checkout must work with JavaScript off (spec §16.3,
 * .claude/rules/storefront-rendering.md). The optional map widget (a later
 * task) posts to exactly this endpoint with exactly this payload, so there
 * is one code path on the server either way.
 */
class PickupPointController
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly PickupPointCatalog $points,
    ) {}

    public function show(Request $request): Response
    {
        $cart = $this->carts->forToken(CartCookie::read($request));
        $query = trim((string) $request->query('q', ''));

        $view = view('checkout::checkout.pickup-point', [
            'query' => $query,
            'points' => $query === '' ? collect() : $this->points->search($query),
            'selected' => $cart->cartPickupPointCode(),
            'seo' => new Seo(title: 'Výdejní místo', noindex: true),
        ]);

        return CartCookie::attach($this->uncached($view), $cart, $request);
    }

    public function store(Request $request): RedirectResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));
        $code = (string) $request->input('pickup_point_code', '');

        // Only the code is trusted. Name and address are always re-read from
        // the catalogue — a forged POST could otherwise print an address of
        // the buyer's choosing on the order (same policy as variant_id,
        // wave 2.4). An unknown or deactivated code is refused, never
        // written silently.
        $point = $code === '' ? null : $this->points->find(ShippingMethod::PROVIDER_PACKETA, $code);

        if ($point === null) {
            return CartCookie::attach(
                redirect()
                    ->route('storefront.checkout.pickupPoint')
                    ->withErrors(['pickup_point_code' => 'Toto výdejní místo neznáme nebo už není v provozu. Vyberte prosím jiné.']),
                $cart,
                $request,
            );
        }

        $this->carts->choosePickupPoint($cart, $point->pointCode());

        return CartCookie::attach(
            redirect()->route('storefront.checkout.shipping'),
            $cart,
            $request,
        );
    }

    /**
     * Same explicit `Cache-Control: private, no-store` every other checkout
     * page in this module uses — no page-cache layer exists yet in this
     * codebase to register a route exclusion with.
     */
    private function uncached(View $view): Response
    {
        return response($view)->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
