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
        return $this->lowest(
            $product->getKey(),
            null,
            $product->saleIsRunning(),
            $product->effectivePrice(),
        );
    }

    public function forVariant(ProductVariant $variant): ?Money
    {
        $variant->loadMissing('product');

        return $this->lowest(
            $variant->product_id,
            $variant->getKey(),
            $variant->saleIsRunning(),
            $variant->effectivePrice(),
        );
    }

    private function lowest(int $productId, ?int $variantId, bool $onSale, Money $current): ?Money
    {
        $now = CarbonImmutable::now();

        $rows = fn () => ProductPriceHistory::query()
            ->where('product_id', $productId)
            ->where(fn ($query) => $variantId === null
                ? $query->whereNull('variant_id')
                : $query->where('variant_id', $variantId));

        // The law asks for the lowest price of the 30 days BEFORE the discount
        // was granted, so a running campaign must not be part of its own
        // reference — counting it in would make every reference equal to the
        // sale price and every announced discount 0 %.
        $reference = $now;

        if ($onSale) {
            $running = $rows()
                ->where('starts_at', '<=', $now)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
                ->orderByDesc('starts_at')
                ->first();

            $reference = $running === null
                ? $now
                : CarbonImmutable::instance($running->starts_at);
        }

        $from = $reference->subDays(self::WINDOW_DAYS);

        $amount = $rows()
            // Overlapping the window, not contained in it: a price set two
            // months ago and still standing when the campaign began is the
            // price of those 30 days.
            ->where('starts_at', '<', $reference)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $from))
            ->min('price');

        // A product launched straight into a campaign has no history older
        // than the campaign. The honest reference is then the price it is
        // selling at — which makes the discount against it zero, and the view
        // renders the line without a percentage badge.
        return $amount === null ? $current : new Money((int) $amount, $current->currency);
    }
}
