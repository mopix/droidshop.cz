<?php

namespace Modules\Products\Services;

use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductPriceHistory;
use Modules\Products\Models\ProductVariant;

/**
 * Keeps product_price_history in step with the price a product is actually
 * sold at — the evidence behind "lowest price in the last 30 days".
 *
 * Future intervals are written up front. A campaign that ends at midnight has
 * to already be bounded in the series, because nothing runs on a schedule to
 * notice that it ended, and a shop that never touches the product again would
 * otherwise show its sale price as the 30-day low forever.
 *
 * What is already closed is never touched: history is a document for a
 * regulator, and a rewritten one is worse than a missing one.
 */
class PriceHistoryRecorder
{
    /**
     * Records the product and every variant it carries.
     *
     * Both, always: a product-level change moves the price of every variant
     * that inherits it, and a variant-level row is what the picker needs.
     */
    public function record(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $this->write($product, null, $product->effectivePrice(), $product->price);

            $product->loadMissing('variants');

            foreach ($product->variants as $variant) {
                $variant->setRelation('product', $product);
                $this->write($product, $variant, $variant->effectivePrice(), $variant->regularPrice());
            }
        });
    }

    public function recordVariant(ProductVariant $variant): void
    {
        $variant->loadMissing('product');

        DB::transaction(function () use ($variant): void {
            $this->write(
                $variant->product,
                $variant,
                $variant->effectivePrice(),
                $variant->regularPrice(),
            );
        });
    }

    private function write(Product $product, ?ProductVariant $variant, Money $effective, Money $regular): void
    {
        $now = CarbonImmutable::now();
        $variantId = $variant?->getKey();

        $rows = ProductPriceHistory::query()
            ->where('product_id', $product->getKey())
            ->where(fn ($query) => $variantId === null
                ? $query->whereNull('variant_id')
                : $query->where('variant_id', $variantId));

        // Anything that has not started yet is a plan, and plans are replaced.
        (clone $rows)->where('starts_at', '>', $now)->delete();

        // The sale amount that will apply once a scheduled window opens. Read
        // from the variant when there is one, because the product's amount is
        // only inherited by a variant that inherits the base price too — the
        // same rule ProductVariant::saleAmount() applies.
        $scheduledSale = $variant === null
            ? $product->sale_price
            : ($variant->sale_price ?? ($variant->price === null ? $product->sale_price : null));

        $segments = $this->segments($product, $effective, $regular, $now, $scheduledSale);

        // The row in effect right now — which is not the same as "the row with
        // no end": rescheduling a campaign leaves the running row bounded at
        // the old start date, and treating that as absent would insert a
        // second, overlapping interval for the same minutes.
        $current = (clone $rows)
            ->where('starts_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderByDesc('starts_at')
            ->first();

        $first = array_shift($segments);

        if ($current !== null && $current->price->amount === $first['price']->amount) {
            // Same price as before: move the end of the interval that is still
            // running instead of fragmenting the series on an unrelated save
            // (a renamed product must not look like a price change). Only the
            // end moves, and only while it lies in the future.
            $current->ends_at = $first['ends_at'];
            $current->save();
        } else {
            if ($current !== null) {
                $current->ends_at = $now;
                $current->save();
            }

            $this->insert($product, $variantId, $first);
        }

        foreach ($segments as $segment) {
            $this->insert($product, $variantId, $segment);
        }
    }

    /**
     * The price timeline from now on, derived from the campaign window.
     *
     * @return list<array{price: Money, starts_at: CarbonImmutable, ends_at: ?CarbonImmutable}>
     */
    private function segments(
        Product $product,
        Money $effective,
        Money $regular,
        CarbonImmutable $now,
        ?Money $scheduledSale,
    ): array {
        $starts = $product->sale_starts_at === null ? null : CarbonImmutable::instance($product->sale_starts_at);
        $ends = $product->sale_ends_at === null ? null : CarbonImmutable::instance($product->sale_ends_at);

        // Compared, not asked: the same method serves a product and a variant,
        // and a variant is "on sale" by virtue of its own amount.
        $running = $effective->amount !== $regular->amount;

        if ($running) {
            $segments = [['price' => $effective, 'starts_at' => $now, 'ends_at' => $ends]];

            if ($ends !== null) {
                $segments[] = ['price' => $regular, 'starts_at' => $ends, 'ends_at' => null];
            }

            return $segments;
        }

        // Not running now. It may still be scheduled — and if it is, the whole
        // future belongs in the table already.
        $scheduled = $scheduledSale !== null && $starts !== null && $starts->greaterThan($now);

        if (! $scheduled) {
            return [['price' => $regular, 'starts_at' => $now, 'ends_at' => null]];
        }

        $segments = [
            ['price' => $regular, 'starts_at' => $now, 'ends_at' => $starts],
            ['price' => $scheduledSale, 'starts_at' => $starts, 'ends_at' => $ends],
        ];

        if ($ends !== null) {
            $segments[] = ['price' => $regular, 'starts_at' => $ends, 'ends_at' => null];
        }

        return $segments;
    }

    /**
     * @param  array{price: Money, starts_at: CarbonImmutable, ends_at: ?CarbonImmutable}  $segment
     */
    private function insert(Product $product, ?int $variantId, array $segment): void
    {
        ProductPriceHistory::query()->create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->getKey(),
            'variant_id' => $variantId,
            'price' => $segment['price'],
            'starts_at' => $segment['starts_at'],
            'ends_at' => $segment['ends_at'],
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
