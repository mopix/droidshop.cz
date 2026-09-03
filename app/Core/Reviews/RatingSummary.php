<?php

namespace App\Core\Reviews;

/**
 * What the storefront needs to draw stars, and nothing more.
 *
 * A value object rather than the Eloquent model, because the storefront must
 * keep working when the reviews module is switched off — and a null binding
 * cannot return a model whose table may not exist.
 */
final readonly class RatingSummary
{
    /**
     * @param  array<int, int>  $breakdown  stars 1–5 mapped to their counts
     */
    public function __construct(
        public float $average,
        public int $count,
        public array $breakdown = [],
    ) {}
}
