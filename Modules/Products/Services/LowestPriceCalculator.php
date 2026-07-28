<?php

namespace Modules\Products\Services;

use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductPriceHistory;
use Modules\Products\Models\ProductVariant;

/**
 * The lowest price a product was actually sold at over the statutory window
 * — the figure that must appear next to an announced discount (§ 12a of act
 * 634/1992 Sb., the Omnibus directive).
 *
 * The window is a constant, not a setting: the law fixes it at 30 days and a
 * shop must not be able to shorten it from the admin.
 */
class LowestPriceCalculator
{
    public const WINDOW_DAYS = 30;

    public function forProduct(Product $product): ?Money
    {
        return $this->lowest($product->getKey(), null, $product->price->currency);
    }

    public function forVariant(ProductVariant $variant): ?Money
    {
        $variant->loadMissing('product');

        return $this->lowest(
            $variant->product_id,
            $variant->getKey(),
            $variant->regularPrice()->currency,
        );
    }

    private function lowest(int $productId, ?int $variantId, string $currency): ?Money
    {
        $now = CarbonImmutable::now();
        $from = $now->subDays(self::WINDOW_DAYS);

        $amount = ProductPriceHistory::query()
            ->where('product_id', $productId)
            ->where(fn ($query) => $variantId === null
                ? $query->whereNull('variant_id')
                : $query->where('variant_id', $variantId))
            // Overlapping the window, not contained in it: a price set two
            // months ago and still running is the price of the last 30 days.
            ->where('starts_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $from))
            ->min('price');

        return $amount === null ? null : new Money((int) $amount, $currency);
    }
}
