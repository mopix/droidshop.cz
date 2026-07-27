<?php

namespace App\Core\Discounts;

use App\Core\Discounts\Contracts\DiscountEngine;

/**
 * The kernel's default: a deploy (or a tenant) without the discounts module
 * simply gets no discount, never an error — the same guest-safe stance
 * NullShippingOptions takes. A typed code is ignored rather than rejected:
 * with no module there is nothing to reject it against, and the field that
 * would have submitted it is not rendered either.
 */
final class NullDiscountEngine implements DiscountEngine
{
    public function apply(DiscountContext $context): AppliedDiscount
    {
        return AppliedDiscount::none($context->itemsTotal->currency);
    }
}
