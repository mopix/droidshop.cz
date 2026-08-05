<?php

namespace Modules\Feeds\Support;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Models\Tenant;

/**
 * The one place that knows what a feed's cache key looks like.
 *
 * `FeedController` reads through this, `FeedAdminController` invalidates
 * through it — two independent constructions of the same string drift apart
 * the moment either side changes (this wave already spent a full fix round
 * undoing exactly that kind of drift for the search-term fold in the page
 * cache key). Keyed on `Dimension::Catalog` only, and scoped to the single
 * `$type` a caller asks about: forgetting one feed's key must never touch
 * the other feed type, let alone the rest of the tenant's catalogue-facing
 * cache (product pages, category pages, the sitemap all read the same
 * generation column).
 */
class FeedCache
{
    /**
     * @var list<Dimension>
     */
    public const DIMENSIONS = [Dimension::Catalog];

    public function __construct(private readonly Generations $generations) {}

    public function key(Tenant $tenant, string $type): string
    {
        return 'feed:'.$tenant->id.':'.$type.':'.$this->generations->stamp($tenant, self::DIMENSIONS);
    }
}
