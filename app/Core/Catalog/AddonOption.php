<?php

namespace App\Core\Catalog;

use App\Core\Money\Money;

/**
 * One addon as everything outside the catalogue sees it.
 *
 * Carries its own rate because the cart, the order and the document each need
 * to tax it on its own terms — folding it into the product's rate is the kind
 * of shortcut that shows up in an audit rather than in a test.
 */
final readonly class AddonOption
{
    public function __construct(
        public int $id,
        public int $groupId,
        public string $label,
        public Money $price,
        public float $taxRatePercent,
        public ?string $imageUrl = null,
    ) {}
}
