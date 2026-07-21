<?php

namespace Modules\Shipping\Services;

use App\Core\Shipping\Contracts\PaymentOption;
use App\Core\Shipping\Contracts\PaymentOptions;
use Illuminate\Support\Collection;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Storefront\Support\ShopModules;

class EloquentPaymentOptions implements PaymentOptions
{
    public function __construct(private readonly ShopModules $modules) {}

    public function forShipping(int $shippingMethodId): Collection
    {
        if (! $this->modules->has('shipping')) {
            return new Collection;
        }

        $shipping = ShippingMethod::find($shippingMethodId);

        if ($shipping === null) {
            return new Collection;
        }

        $active = PaymentMethod::query()->where('is_active', true)->orderBy('position');

        $linkedIds = $shipping->paymentMethods()->pluck('payment_methods.id');

        // No matrix rows for this shipping method → every active payment is
        // allowed (plan decision 1). Otherwise restrict to the linked ones.
        if ($linkedIds->isNotEmpty()) {
            $active->whereIn('id', $linkedIds);
        }

        return $active->get();
    }

    public function find(int $id): ?PaymentOption
    {
        if (! $this->modules->has('shipping')) {
            return null;
        }

        return PaymentMethod::find($id);
    }
}
