<?php

namespace App\Core\Catalog;

/**
 * A question the product page asks, and the answers it accepts.
 */
final readonly class AddonGroup
{
    /**
     * @param  list<AddonOption>  $options
     */
    public function __construct(
        public int $id,
        public string $label,
        public bool $required,
        public array $options,
    ) {}
}
