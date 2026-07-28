<?php

namespace Modules\Feeds\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether a shop publishes a given feed, and how.
 */
class ProductFeed extends Model
{
    use BelongsToTenant;

    public const TYPE_HEUREKA = 'heureka';

    public const TYPE_ZBOZI = 'zbozi';

    /** @var list<string> */
    public const TYPES = [self::TYPE_HEUREKA, self::TYPE_ZBOZI];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * Days until dispatch for a product that is not in stock. Zero would claim
     * same-day delivery on something the shop does not have, so the default is
     * deliberately non-zero.
     */
    public function deliveryDays(): int
    {
        return (int) ($this->settings['delivery_date'] ?? 7);
    }
}
