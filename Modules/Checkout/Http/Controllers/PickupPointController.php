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
        [$carrier, $optionId] = $this->resolveCarrier($request, $cart);
        $query = trim((string) $request->query('q', ''));

        $view = view('checkout::checkout.pickup-point', [
            'query' => $query,
            'points' => $query === '' ? collect() : $this->points->search($carrier, $query),
            'selected' => $cart->cartPickupPointCode(),
            'seo' => new Seo(title: 'Výdejní místo', noindex: true),
            'widgetApiKey' => $this->widgetApiKey($carrier),
            // Round-tripped through every form on this page (search, results,
            // the widget's data-action) so the NEXT request — a search, a
            // point selection, the widget's own POST — still knows which
            // shipping option opened the picker (review finding I2).
            'shippingMethodId' => $optionId,
        ]);

        return CartCookie::attach($this->uncached($view), $cart, $request);
    }

    public function store(Request $request): RedirectResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));
        [$carrier, $optionId] = $this->resolveCarrier($request, $cart);
        $code = (string) $request->input('pickup_point_code', '');

        // Only the code is trusted. Name and address are always re-read from
        // the catalogue — a forged POST could otherwise print an address of
        // the buyer's choosing on the order (same policy as variant_id,
        // wave 2.4). An unknown or deactivated code is refused, never
        // written silently.
        $point = $code === '' ? null : $this->points->find($carrier, $code);

        $backParams = $optionId !== null ? ['shipping_method_id' => $optionId] : [];

        if ($point === null) {
            return CartCookie::attach(
                redirect()
                    ->route('storefront.checkout.pickupPoint', $backParams)
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
     * The carrier this picker searches, and the shipping-method id that
     * decided it (or null when none did) — the latter is round-tripped back
     * into the view so every subsequent request on this page keeps deciding
     * the same way.
     *
     * Review finding I2: this used to derive the carrier ONLY from the
     * shipping method the cart already has chosen (cartShippingMethodId()).
     * That breaks in a shop offering both a pickup-point carrier and a
     * carrier delivering to the shopper's own address (Packeta home
     * delivery, this wave): the "Vybrat výdejní místo" link sits under EVERY
     * pickup-point option in the shipping list
     * (checkout/shipping.blade.php), regardless of which one is currently
     * selected. A shopper who already submitted "doručení na adresu" (cart
     * now holds that method) and then opens the picker link under the
     * SEPARATE, not-yet-submitted branch-pickup radio was searching under
     * the wrong provider and finding nothing — before this fix, there was no
     * way to tell the picker "no, THIS option" at all.
     *
     * Fixed by carrying the clicked option's own id on the link
     * (?shipping_method_id=…) and resolving through THAT first.
     * ShippingOptions::find() is tenant-scoped to active methods (the same
     * "cart's available options" a forged id could not otherwise fake its
     * way into) and needs no credentials, unlike CarrierRegistry — so the
     * picker still opens for a tenant who has not configured a carrier's API
     * password yet, same as before.
     *
     * The cart-derived fallback stays for a request that carries no id at
     * all — a bookmarked URL, a direct GET, or (now) the store() redirect
     * when the id round-tripped from show() failed to resolve, i.e. exactly
     * the pre-fix behaviour for those cases. No shipping method chosen at
     * all falls back further to the one pickup-point carrier the catalogue
     * serves today — a second one will need this default resolved another
     * way, same caveat as before this fix.
     *
     * @return array{0: string, 1: ?int}
     */
    private function resolveCarrier(Request $request, CartShape $cart): array
    {
        $requestedId = $request->input('shipping_method_id');
        $option = $requestedId !== null ? $this->shippingOptions->find((int) $requestedId) : null;

        if ($option !== null) {
            return [$option->provider(), $option->id()];
        }

        $shippingMethodId = $cart->cartShippingMethodId();

        $provider = $shippingMethodId !== null
            ? $this->shippingOptions->find($shippingMethodId)?->provider()
            : null;

        return [$provider ?? ShippingMethod::PROVIDER_PACKETA, null];
    }
}
