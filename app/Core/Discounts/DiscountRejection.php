<?php

namespace App\Core\Discounts;

/**
 * Why a code the shopper typed does not apply.
 *
 * Carried on the result rather than thrown: a rejected code must not stop the
 * cart from rendering, and the shopper has to be told the reason instead of
 * silently seeing the old total (AK 2).
 */
final readonly class DiscountRejection
{
    public const NOT_FOUND = 'not_found';

    public const INACTIVE = 'inactive';

    public const EXPIRED = 'expired';

    public const NOT_STARTED = 'not_started';

    public const MIN_CART = 'min_cart';

    public const NO_ELIGIBLE_ITEMS = 'no_eligible_items';

    public const REQUIRES_LOGIN = 'requires_login';

    public const FIRST_ORDER_ONLY = 'first_order_only';

    public const USAGE_LIMIT = 'usage_limit';

    public const EMAIL_LIMIT = 'email_limit';

    public function __construct(
        public string $code,
        public string $reason,
    ) {}
}
