<?php

namespace Modules\Feeds\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One shop category expressed in a comparison shopper's own taxonomy.
 */
class FeedCategoryMapping extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
}
