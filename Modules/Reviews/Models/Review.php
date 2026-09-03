<?php

namespace Modules\Reviews\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Reviews\Database\Factories\ReviewFactory;

/**
 * A product review or a shop rating — one table, because the two differ in a
 * single column and a second table would duplicate moderation, tokens and
 * aggregation wholesale.
 */
class Review extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const SUBJECT_PRODUCT = 'product';

    public const SUBJECT_SHOP = 'shop';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    /** The shop itself, in the column that otherwise holds a product id. */
    public const SUBJECT_SHOP_KEY = 0;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'verified_purchase' => 'boolean',
            'moderated_at' => 'datetime',
            'reply_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ReviewFactory
    {
        return ReviewFactory::new();
    }
}
