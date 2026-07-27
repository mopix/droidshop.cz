<?php

namespace Modules\Discounts\Services;

use App\Core\Discounts\AppliedDiscount;
use App\Core\Discounts\Contracts\DiscountEngine;
use App\Core\Discounts\DiscountContext;

/**
 * Placeholder so the module can boot once activated (Task 2). The real
 * matching, stacking and allocation logic is written in Task 4 — until then
 * this behaves like the kernel's NullDiscountEngine: no discount, ever.
 */
final class DiscountEvaluator implements DiscountEngine
{
    public function apply(DiscountContext $context): AppliedDiscount
    {
        return AppliedDiscount::none($context->itemsTotal->currency);
    }
}
