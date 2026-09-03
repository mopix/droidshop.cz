<?php

namespace Modules\Reviews\Services;

use Illuminate\Support\Facades\DB;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Models\ReviewAggregate;

/**
 * The one place a rating average is produced.
 *
 * Every moderation action calls this rather than adjusting counters by hand:
 * incremental arithmetic is how an aggregate drifts away from the rows it
 * claims to summarise, and there is no way to notice from the storefront.
 */
class ReviewAggregator
{
    /**
     * @param  int  $productId  0 means the shop's own rating
     */
    public function recalculate(int $productId): ReviewAggregate
    {
        $rows = Review::query()
            ->where('product_id', $productId)
            ->where('status', Review::STATUS_PUBLISHED)
            ->select('rating', DB::raw('count(*) as total'))
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $count = 0;
        $sum = 0;
        $breakdown = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

        foreach ($rows as $rating => $total) {
            $rating = (int) $rating;
            $total = (int) $total;

            $breakdown[$rating] = $total;
            $count += $total;
            $sum += $rating * $total;
        }

        return ReviewAggregate::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'rating_avg' => $count > 0 ? round($sum / $count, 1) : 0,
                'rating_count' => $count,
                'count_1' => $breakdown[1],
                'count_2' => $breakdown[2],
                'count_3' => $breakdown[3],
                'count_4' => $breakdown[4],
                'count_5' => $breakdown[5],
            ],
        );
    }
}
