<?php

namespace App\Core\Payments;

use App\Core\Payments\Contracts\PaymentGateway;
use App\Core\Payments\Contracts\PaymentGatewayRegistry;

/**
 * The kernel's own answer to PaymentGatewayRegistry, bound by default
 * (App\Providers\AppServiceProvider) and overridden by
 * Modules\Payments\Providers\ModuleProvider whenever that module is part of
 * the deploy.
 *
 * Unlike NullOrderPlacement, an empty answer here is the correct guest-safe
 * behaviour: a shop with no online gateway is a normal shop that takes cash
 * on delivery and bank transfer. for() returning null makes checkout skip the
 * gateway redirect and fall through to the offline flow; available() being
 * empty makes it never offer an online method in the first place.
 */
final class NullPaymentGatewayRegistry implements PaymentGatewayRegistry
{
    public function for(string $provider): ?PaymentGateway
    {
        return null;
    }

    public function available(): array
    {
        return [];
    }
}
