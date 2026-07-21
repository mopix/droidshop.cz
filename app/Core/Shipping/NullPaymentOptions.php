<?php

namespace App\Core\Shipping;

use App\Core\Shipping\Contracts\PaymentOption;
use App\Core\Shipping\Contracts\PaymentOptions;
use Illuminate\Support\Collection;

/**
 * The answer when no shop is taking payment: none.
 *
 * Bound in the kernel so app(PaymentOptions::class) always resolves, even on
 * a deploy without the shipping module. The module overrides it.
 */
class NullPaymentOptions implements PaymentOptions
{
    public function forShipping(int $shippingMethodId): Collection
    {
        return new Collection;
    }

    public function find(int $id): ?PaymentOption
    {
        return null;
    }
}
