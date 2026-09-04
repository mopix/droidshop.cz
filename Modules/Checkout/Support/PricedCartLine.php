<?php

namespace Modules\Checkout\Support;

use App\Core\Money\Money;

/**
 * One cart line as `/kosik` renders it — every price already recomputed from
 * the catalogue, never from `cart_items.unit_price` (spec §16.3, rozhodnutí
 * 2). `previousUnitPrice` is only set when it differs from `unitPrice`, so a
 * view can key the change banner off its presence alone.
 */
final readonly class PricedCartLine
{
    public function __construct(
        public int $itemId,
        public int $productId,
        public string $name,
        public ?string $url,
        public ?string $imageUrl,
        public int $quantity,
        public Money $unitPrice,
        public Money $lineTotal,
        public bool $priceChanged,
        public ?Money $previousUnitPrice,
        /**
         * False when the product left the catalogue (unpublished or
         * deleted) between being added and this render — the line still
         * shows so the shopper can remove it, but it never counts toward
         * itemsTotal.
         */
        public bool $available,
        /** 0/null when the line is a plain product without variants. */
        public ?int $variantId = null,
        public ?string $variantLabel = null,
        /**
         * Set on an accessory line, naming the product line it belongs to.
         *
         * The cart renders it under its parent and the quantity controls stay
         * on the parent alone: an accessory the shopper could count separately
         * from the thing it is attached to is an accessory that ends up
         * ordered three times for one picture.
         */
        public ?int $parentItemId = null,
        public ?int $addonId = null,
        /** How much of the basket's discount lands on this line (0 when none does). */
        public ?Money $discountAmount = null,
        /** lineTotal minus discountAmount — what this line actually costs. */
        public ?Money $discountedLineTotal = null,
    ) {}
}
