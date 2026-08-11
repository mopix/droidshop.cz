<?php

namespace Modules\Checkout\Http\Controllers;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Checkout\Contracts\CartShape;
use App\Core\Shipping\Contracts\PickupPointCatalog;
use App\Core\Shipping\Contracts\ShippingOptions;
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
        private readonly ShippingOptions $shippingOptions,
    ) {}

    public function show(Request $request): Response
    {
        $cart = $this->carts->forToken(CartCookie::read($request));
        $carrier = $this->carrier($cart);
        $query = trim((string) $request->query('q', ''));

        $view = view('checkout::checkout.pickup-point', [
            'query' => $query,
            'points' => $query === '' ? collect() : $this->points->search($carrier, $query),
            'selected' => $cart->cartPickupPointCode(),
            'seo' => new Seo(title: 'Výdejní místo', noindex: true),
            'widgetApiKey' => $this->widgetApiKey($carrier),
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
        $point = $code === '' ? null : $this->points->find($this->carrier($cart), $code);

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

    /**
     * The public widget key of the active method for the derived carrier, if
     * any — never the `api_password` credential sitting next to it in the
     * same encrypted `settings` column. `ShippingMethod::packetaApiKey()`
     * already returns null for any other provider, so this stays a plain
     * pass-through and needs no provider check of its own.
     */
    private function widgetApiKey(string $carrier): ?string
    {
        $method = ShippingMethod::query()
            ->where('provider', $carrier)
            ->where('is_active', true)
            ->first();

        return $method?->packetaApiKey();
    }

    /**
     * The carrier this picker searches — read from the shipping method the
     * cart already has chosen, the same source CheckoutController's
     * requiresPickupPoint() uses, never a hardcoded provider string (AK
     * §16.5: "another carrier without touching checkout").
     * ShippingOptions::find() needs no credentials, unlike CarrierRegistry —
     * so the picker still opens for a tenant who has not configured a
     * carrier's API password yet.
     *
     * Nothing is chosen yet on a first visit to this page (the shopper can
     * reach it before the shipping step), so this falls back to the one
     * carrier the catalogue serves today. A second carrier will need this
     * default resolved another way — nothing in this task adds one.
     */
    private function carrier(CartShape $cart): string
    {
        $shippingMethodId = $cart->cartShippingMethodId();

        $provider = $shippingMethodId !== null
            ? $this->shippingOptions->find($shippingMethodId)?->provider()
            : null;

        return $provider ?? ShippingMethod::PROVIDER_PACKETA;
    }
}
