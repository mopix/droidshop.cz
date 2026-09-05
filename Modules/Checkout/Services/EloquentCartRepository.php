<?php

namespace Modules\Checkout\Services;

use App\Core\Catalog\Contracts\ProductAddons;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Checkout\Contracts\CartShape;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use LogicException;
use Modules\Checkout\Models\Cart;
use Modules\Checkout\Models\CartItem;
use Modules\Storefront\Support\ShopModules;

class EloquentCartRepository implements CartRepository
{
    public function __construct(
        private readonly ShopModules $modules,
        private readonly ProductCatalog $catalog,
        // Through the kernel contract, never the products module: the addon's
        // price and its ownership of the product are decided there, and this
        // repository must keep working on a shop that sells no accessories.
        private readonly ProductAddons $addons,
    ) {}

    /**
     * Narrower than the interface's CartShape return type, which PHP allows
     * (covariant returns) — this implementation is the only source of a real,
     * persisted Cart, so callers inside this module may keep depending on
     * the concrete type instead of re-widening back to the shape.
     */
    public function forToken(?string $token): Cart
    {
        if (! $this->modules->has('checkout')) {
            // The tenant does not run the module: answer with an unsaved
            // cart rather than leaking a row a deactivated module owns, or
            // writing one nobody can reach again.
            return $this->transientCart();
        }

        if ($token !== null) {
            // Cart is BelongsToTenant-scoped already, so a token belonging
            // to another tenant simply never matches here (spec tenant
            // isolation, AK 6) — it falls through to a fresh cart below.
            $existing = Cart::query()->where('token', $token)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return Cart::query()->create([
            'token' => Str::random(40),
            'expires_at' => now()->addDays(14),
        ]);
    }

    public function addItem(CartShape $cart, int $productId, int $quantity, ?int $variantId = null, array $addonIds = []): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        // 0, never null — see the migration: NULL would defeat cart_item_unique.
        $variantKey = $variantId ?? 0;

        // Addons are part of a line's identity: the same picture with an oak
        // frame and with no frame are two things a customer bought, not one
        // line of two. The ids are sorted so the same choice made in a
        // different order still merges.
        $addonIds = collect($addonIds)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        $addonHash = $this->addonHash($addonIds);

        $existing = $this->existingItem($cart, $productId, $variantKey, $addonIds);

        if ($existing !== null) {
            $existing->increment('quantity', $quantity);

            // The accessories move with the thing they belong to; a frame
            // without its picture is not something anyone ordered.
            $existing->addonLines()->increment('quantity', $quantity);

            return;
        }

        try {
            // The price is read from the catalogue at the moment of insertion.
            // It is a snapshot for display only — the pricing authority stays
            // ProductCatalog::price(), read again wherever a total is computed.
            $item = $cart->items()->create([
                'product_id' => $productId,
                'variant_id' => $variantKey,
                'addon_hash' => $addonHash,
                'quantity' => $quantity,
                'unit_price' => $this->catalog->price($productId, [], $variantId),
            ]);

            $this->addAddonLines($cart, $item, $productId, $quantity, $addonIds);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent addItem() for the same product (and variant)
            // committed between our lookup above and this insert — the
            // ordinary shape of a same-product double-click, or two open
            // tabs. cart_item_unique caught it before two rows could exist;
            // merge into the row that won instead of surfacing a 500
            // (mirrors OrderPlacer's own recovery from order_idem_unique).
            $winner = $this->existingItem($cart, $productId, $variantKey);

            if ($winner === null) {
                // Not a duplicate-key collision we can resolve to a row —
                // some other constraint, or the row vanished. Never swallow it.
                throw $e;
            }

            $winner->increment('quantity', $quantity);
        }
    }

    /**
     * protected, not private, so a test can force the lookup to miss even
     * though a row already exists — the only way to exercise the
     * UniqueConstraintViolationException recovery path deterministically in
     * single-threaded PHPUnit (mirrors OrderPlacer::existingOrder()).
     */
    /**
     * @param  list<int>  $addonIds
     */
    protected function existingItem(Cart $cart, int $productId, int $variantId = 0, array $addonIds = []): ?CartItem
    {
        return $cart->items()
            ->whereNull('parent_item_id')
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('addon_hash', $this->addonHash($addonIds))
            ->first();
    }

    /**
     * One value standing for a whole set of accessories.
     *
     * Sorted before hashing, so the same choice made in a different order
     * merges into one line instead of quietly becoming a second one.
     *
     * @param  list<int>  $addonIds
     */
    private function addonHash(array $addonIds): string
    {
        return $addonIds === [] ? '' : md5(implode(',', $addonIds));
    }

    /**
     * The chosen accessories, as lines of their own.
     *
     * Their own lines rather than a surcharge folded into the product's price,
     * because that is how they have to reach the invoice: with their own label
     * and their own VAT rate. A cart that models them differently from the
     * order is a cart whose total nobody can reconcile.
     *
     * The price comes from the catalogue, never from the form, and an addon
     * that does not belong to this product is dropped — otherwise a crafted
     * post buys one picture's cheap frame for another.
     *
     * @param  list<int>  $addonIds
     */
    private function addAddonLines(Cart $cart, CartItem $item, int $productId, int $quantity, array $addonIds): void
    {
        foreach ($addonIds as $addonId) {
            $addon = $this->addons->find($productId, $addonId);

            if ($addon === null) {
                continue;
            }

            $cart->items()->create([
                'product_id' => $productId,
                'variant_id' => 0,
                'addon_id' => $addon->id,
                'parent_item_id' => $item->id,
                'addon_hash' => $item->addon_hash,
                'quantity' => $quantity,
                'unit_price' => $addon->price,
            ]);
        }
    }

    public function setQuantity(CartShape $cart, int $itemId, int $quantity): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        $item = $cart->items()->whereKey($itemId)->first();

        if ($item === null) {
            return;
        }

        if ($quantity <= 0) {
            // Accessories go with the thing they were attached to. Leaving
            // them behind would bill a frame for a picture that is no longer
            // in the basket.
            $item->addonLines()->delete();
            $item->delete();

            return;
        }

        $item->update(['quantity' => $quantity]);
        $item->addonLines()->update(['quantity' => $quantity]);
    }

    public function removeItem(CartShape $cart, int $itemId): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        // Children first, then the line itself: the other order leaves orphan
        // accessory rows pointing at a parent that no longer exists.
        $cart->items()->where('parent_item_id', $itemId)->delete();
        $cart->items()->whereKey($itemId)->delete();
    }

    public function attachToCustomer(CartShape $cart, int $customerId): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        $cart->update(['customer_id' => $customerId]);
    }

    public function findForCustomer(int $customerId): ?Cart
    {
        if (! $this->modules->has('checkout')) {
            return null;
        }

        // orderByDesc('id'), not first(): a customer should only ever
        // acquire one live cart going forward, but this is the read that
        // decides the merge at login, so it stays defensive against any
        // pre-existing duplicate rather than picking an arbitrary one.
        return Cart::query()
            ->where('customer_id', $customerId)
            ->whereNull('converted_at')
            ->orderByDesc('id')
            ->first();
    }

    public function retire(CartShape $cart): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        $cart->update(['converted_at' => now()]);
    }

    public function chooseShipping(CartShape $cart, ?int $shippingMethodId, ?int $paymentMethodId): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        $cart->update([
            'shipping_method_id' => $shippingMethodId,
            'payment_method_id' => $paymentMethodId,
        ]);
    }

    public function choosePickupPoint(CartShape $cart, ?string $code): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        $cart->update(['pickup_point_code' => $code]);
    }

    public function setCouponCode(CartShape $cart, ?string $code): void
    {
        if (! $this->modules->has('checkout')) {
            return;
        }

        $cart = $this->persisted($cart);

        $cart->update(['coupon_code' => $code]);
    }

    private function transientCart(): Cart
    {
        return new Cart([
            'token' => Str::random(40),
            'expires_at' => now()->addDays(14),
        ]);
    }

    /**
     * Narrows the interface's CartShape parameter back to the concrete
     * model these mutators actually need to write through.
     *
     * Every mutator gates on ShopModules::has('checkout') first, so the only
     * CartShape that can reach this point is one this class's own
     * forToken() handed out while the gate was open — always a real Cart.
     * The exception is a defensive backstop against a caller mixing a
     * TransientCart (from NullCartRepository, or from this class's own gate-
     * closed path) into an active-module call, not a path any current
     * caller takes.
     */
    private function persisted(CartShape $cart): Cart
    {
        if (! $cart instanceof Cart) {
            throw new LogicException(
                'CartRepository mutator received a cart that was never persisted by this implementation.'
            );
        }

        return $cart;
    }
}
