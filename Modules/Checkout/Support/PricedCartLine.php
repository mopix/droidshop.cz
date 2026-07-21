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
    ) {}
}
