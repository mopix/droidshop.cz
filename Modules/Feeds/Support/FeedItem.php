<?php

namespace Modules\Feeds\Support;

use App\Core\Money\Money;

/**
 * One SHOPITEM, already resolved.
 *
 * Both feeds render from this shape, so the difference between Heureka and
 * Zboží stays in the templates and never leaks into the catalogue queries.
 */
readonly class FeedItem
{
    /**
     * @param  list<string>  $alternativeImageUrls
     * @param  array<string, string>  $params  axis name => value
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $url,
        public ?string $imageUrl,
        public array $alternativeImageUrls,
        public Money $priceVat,
        public ?string $manufacturer,
        public string $categoryText,
        public ?string $ean,
        public ?string $sku,
        public int $deliveryDays,
        public ?string $itemGroupId,
        public array $params,
    ) {}
}
