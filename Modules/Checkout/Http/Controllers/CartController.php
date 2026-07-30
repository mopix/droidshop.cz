<?php

namespace Modules\Checkout\Http\Controllers;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Money\Money;
use App\Core\Settings\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Checkout\Http\Requests\AddCartItemRequest;
use Modules\Checkout\Http\Requests\UpdateCartItemRequest;
use Modules\Checkout\Services\CartPricer;
use Modules\Checkout\Support\CartCookie;
use Modules\Checkout\Support\StaleCoupon;
use Modules\Storefront\Support\Seo;
use Modules\Storefront\Support\ShopModules;

/**
 * `/kosik` — the whole flow works with JavaScript switched off (spec §16.3,
 * .claude/rules/storefront-rendering.md): every action here is a real HTTP
 * form submit (POST/PATCH/DELETE via `_method`) that redirects back to a
 * freshly server-rendered page, never a fetch the page depends on to show
 * its own contents.
 *
 * show() is the one read here that also writes: a coupon this render finds
 * rejected is dropped off the cart (StaleCoupon) so "a code still on the cart"
 * always means "valid at the last render". See that class for why the
 * invariant is worth a write on a GET.
 */
class CartController
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CartPricer $pricer,
        private readonly ProductCatalog $catalog,
        private readonly ShopModules $modules,
        private readonly SettingsService $settings,
    ) {}

    public function show(Request $request): Response
    {
        $cart = $this->carts->forToken(CartCookie::read($request));
        $priced = $this->pricer->price($cart);

        StaleCoupon::clear($this->carts, $cart, $priced);

        // Below the shop's own floor the cart says so and drops the continue
        // button. Presentation only — CheckoutController enforces the same
        // floor on the server, so a stale tab cannot post its way past it.
        $minimum = (int) $this->settings->get('checkout', 'min_order_total', 0);
        $payable = $priced->payableTotal ?? $priced->itemsTotal;
        $belowMinimum = $minimum > 0 && ! $priced->isEmpty() && $payable->amount < $minimum;

        $view = view('checkout::cart', [
            'cart' => $priced,
            'minimumOrderTotal' => $belowMinimum ? new Money($minimum, $payable->currency) : null,
            'seo' => new Seo(title: 'Košík', noindex: true),
            // The discount field renders only for a shop that actually runs
            // the module — decided here, once, rather than the partial
            // resolving ShopModules itself out of the container.
            'discountsEnabled' => $this->modules->has('discounts'),
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
        $product = $this->catalog->findById($request->integer('product_id'));

        if ($product === null) {
            abort(404);
        }

        $variantId = null;

        if ($product->catalogHasVariants()) {
            // The server decides which variant a selection means. A missing,
            // partial or foreign selection is refused outright — never
            // silently resolved to "the first one". The client never posts
            // a variant_id directly.
            $variant = $this->catalog->resolveVariant(
                $request->integer('product_id'),
                $request->input('option_value_id', []),
            );

            if ($variant === null || ! $variant->catalogVariantIsAvailable($request->integer('quantity'))) {
                return back()->withErrors([
                    'option_value_id' => 'Zvolte prosím dostupnou variantu produktu.',
                ]);
            }

            $variantId = (int) $variant->getKey();
        }

        $cart = $this->carts->forToken(CartCookie::read($request));

        $this->carts->addItem($cart, $request->integer('product_id'), $request->integer('quantity'), $variantId);

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
