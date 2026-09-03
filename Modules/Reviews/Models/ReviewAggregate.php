<?php

namespace Modules\Reviews\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A precomputed rating per product (and one row with product_id = 0 for the
 * shop). Computed rather than queried because a category listing renders
 * twenty-four products and twenty-four AVG() subqueries is how a fast page
 * becomes a slow one.
 */
class ReviewAggregate extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // Cast, not left as a plain attribute: the column is decimal(2,1)
            // and PHP's float-to-string conversion drops a trailing ".0"
            // (5.0 becomes "5"), which is not what the storefront or the
            // JSON-LD Offer/AggregateRating markup expects to print.
            'rating_avg' => 'decimal:1',
            'rating_count' => 'integer',
            'count_1' => 'integer',
            'count_2' => 'integer',
            'count_3' => 'integer',
            'count_4' => 'integer',
            'count_5' => 'integer',
        ];
    }
}
