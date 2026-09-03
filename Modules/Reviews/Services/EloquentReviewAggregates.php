<?php

namespace Modules\Reviews\Services;

use App\Core\Reviews\Contracts\ReviewAggregates;
use App\Core\Reviews\RatingSummary;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Models\ReviewAggregate;

class EloquentReviewAggregates implements ReviewAggregates
{
    public function forProduct(int $productId): ?RatingSummary
    {
        return $this->summarise(
            ReviewAggregate::query()->where('product_id', $productId)->first()
        );
    }

    public function forProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return ReviewAggregate::query()
            ->whereIn('product_id', $productIds)
            ->where('rating_count', '>', 0)
            ->get()
            ->mapWithKeys(fn (ReviewAggregate $row): array => [
                (int) $row->product_id => $this->summarise($row),
            ])
            ->all();
    }

    public function forShop(): ?RatingSummary
    {
        return $this->summarise(
            ReviewAggregate::query()->where('product_id', Review::SUBJECT_SHOP_KEY)->first()
        );
    }

    private function summarise(?ReviewAggregate $row): ?RatingSummary
    {
        // An aggregate with no reviews is not a rating of zero, it is the
        // absence of a rating — and the difference matters, because a
        // zero-star product in JSON-LD is a Rich Results error.
        if ($row === null || $row->rating_count === 0) {
            return null;
        }

        return new RatingSummary(
            average: (float) $row->rating_avg,
            count: $row->rating_count,
            breakdown: [
                1 => $row->count_1,
                2 => $row->count_2,
                3 => $row->count_3,
                4 => $row->count_4,
                5 => $row->count_5,
            ],
        );
    }
}
