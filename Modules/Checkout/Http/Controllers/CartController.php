<?php

namespace Modules\Checkout\Http\Controllers;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Checkout\Contracts\CartRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Checkout\Http\Requests\AddCartItemRequest;
use Modules\Checkout\Http\Requests\UpdateCartItemRequest;
use Modules\Checkout\Services\CartPricer;
use Modules\Checkout\Support\CartCookie;
use Modules\Storefront\Support\Seo;

/**
 * `/kosik` — the whole flow works with JavaScript switched off (spec §16.3,
 * .claude/rules/storefront-rendering.md): every action here is a real HTTP
 * form submit (POST/PATCH/DELETE via `_method`) that redirects back to a
 * freshly server-rendered page, never a fetch the page depends on to show
 * its own contents.
 */
class CartController
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CartPricer $pricer,
        private readonly ProductCatalog $catalog,
    ) {}

    public function show(Request $request): Response
    {
        $cart = $this->carts->forToken(CartCookie::read($request));

        $view = view('checkout::cart', [
            'cart' => $this->pricer->price($cart),
            'seo' => new Seo(title: 'Košík', noindex: true),
        ]);

        return CartCookie::attach($this->uncached($view), $cart, $request);
    }

    public function add(AddCartItemRequest $request): RedirectResponse
    {
        // findById() is tenant-scoped and only answers a product a customer
        // may actually see (published, this shop) — the same authority the
        // repository reads the price from a moment later. A product id that
        // does not resolve here is either fabricated or belongs to another
        // tenant, so it is treated as "not found", not "forbidden": which
        // one it is is nobody outside the shop's business.
        if ($this->catalog->findById($request->integer('product_id')) === null) {
            abort(404);
        }

        $cart = $this->carts->forToken(CartCookie::read($request));

        $this->carts->addItem($cart, $request->integer('product_id'), $request->integer('quantity'));

        return CartCookie::attach(
            redirect()->route('storefront.checkout.show')->with('status', 'Přidáno do košíku.'),
            $cart,
            $request,
        );
    }

    public function update(UpdateCartItemRequest $request, int $item): RedirectResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));

        // setQuantity() only ever touches a row that belongs to $cart (it
        // queries through the cart's own items() relation) — an item id
        // from a different cart simply matches nothing and is a no-op, so
        // no separate ownership check is needed here.
        $this->carts->setQuantity($cart, $item, $request->integer('quantity'));

        return CartCookie::attach(
            redirect()->route('storefront.checkout.show'),
            $cart,
            $request,
        );
    }

    public function remove(Request $request, int $item): RedirectResponse
    {
        $cart = $this->carts->forToken(CartCookie::read($request));

        $this->carts->removeItem($cart, $item);

        return CartCookie::attach(
            redirect()->route('storefront.checkout.show')->with('status', 'Položka byla odebrána z košíku.'),
            $cart,
            $request,
        );
    }

    /**
     * `/kosik` is never a candidate for any cache a future page-cache layer
     * builds (spec §15.6, rozhodnutí 2026-07-19: no `has_cart` cookie
     * switch — a route-level rule instead). No such layer exists in this
     * codebase yet to register an exclusion with, so this header is the
     * concrete mechanism today: the same explicit
     * `Cache-Control: private, no-store` CustomerAdminController::export()
     * already uses for a PII response.
     */
    private function uncached(View $view): Response
    {
        return response($view)->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
