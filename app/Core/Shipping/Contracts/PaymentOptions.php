<?php

namespace App\Core\Shipping\Contracts;

use Illuminate\Support\Collection;

interface PaymentOptions
{
    /**
     * Active payment methods allowed with the given shipping method.
     *
     * A shipping method with no matrix rows allows every active payment
     * method; adding a row narrows it to the listed ones. That default keeps
     * an untouched matrix screen from producing a shop that can take no order.
     *
     * @return Collection<int, PaymentOption>
     */
    public function forShipping(int $shippingMethodId): Collection;

    public function find(int $id): ?PaymentOption;
}
