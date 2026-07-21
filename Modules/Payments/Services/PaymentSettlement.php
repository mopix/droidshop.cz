<?php

namespace Modules\Payments\Services;

use App\Core\Orders\Contracts\OrderSettlement;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Payments\Contracts\PaymentGatewayRegistry;
use App\Core\Payments\PaymentStatus;

/**
 * The one place a gateway payment is settled — shared by the browser return and
 * the server-to-server webhook, so both decide identically (spec §16.6).
 *
 * Verify-before-trust lives here: whatever the caller was told (a return query,
 * a notification body), this re-asks the gateway for the true state through
 * verify() and only then moves the order, via the OrderSettlement kernel
 * contract. Both settle() calls are idempotent, so a return and a webhook
 * racing on the same order leave it in one state and write one event.
 */
final class PaymentSettlement
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly OrderSettlement $settlement,
    ) {}

    public function settle(OrderView $order): PaymentStatus
    {
        $reference = $order->orderPaymentReference();

        // No reference means no gateway payment was ever started for this
        // order — nothing to verify or settle.
        if ($reference === null) {
            return PaymentStatus::Pending;
        }

        $provider = $order->orderPaymentSnapshot()['provider'] ?? null;
        $gateway = is_string($provider) ? $this->gateways->for($provider) : null;

        if ($gateway === null) {
            return PaymentStatus::Pending;
        }

        $result = $gateway->verify($reference);
        $uuid = $order->orderUuid();

        if ($result->isPaid()) {
            // A paid result for the wrong amount is not a paid order: leave it
            // unpaid rather than settle a figure that does not match. (A real
            // mismatch is a misconfiguration or tampering, not a normal path.)
            if (! $result->amount->equals($order->orderTotal())) {
                return PaymentStatus::Pending;
            }

            $this->settlement->settlePaid($uuid, 'Platba potvrzena bránou (ref '.$reference.').');

            return PaymentStatus::Paid;
        }

        if ($result->isFailed()) {
            // Return the stock the placement took: an abandoned or declined
            // online payment must not hold it forever.
            $this->settlement->settleFailed($uuid, returnStock: true, note: 'Platba u brány selhala nebo byla zrušena (ref '.$reference.').');

            return PaymentStatus::Failed;
        }

        return PaymentStatus::Pending;
    }
}
