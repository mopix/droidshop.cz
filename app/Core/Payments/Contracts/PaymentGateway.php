<?php

namespace App\Core\Payments\Contracts;

use App\Core\Payments\PaymentResult;

/**
 * One online payment gateway (Comgate today; GoPay, Stripe later), as the
 * rest of the platform sees it (spec §16.6).
 *
 * A driver is never resolved directly — checkout, the browser return and the
 * webhook all reach it through PaymentGatewayRegistry::for($provider), keyed
 * by payment_methods.provider. That indirection is what lets a second gateway
 * be added as a registered driver with no change to the checkout or webhook.
 *
 * The interface lives in the kernel, its implementations
 * (Modules\Payments\Services\ComgateGateway, …) in the payments module.
 */
interface PaymentGateway
{
    /** The provider key this driver answers to, e.g. 'comgate'. */
    public function provider(): string;

    /**
     * Starts a payment for an already-placed order and returns where to send
     * the shopper. The order exists and is unpaid at this point; nothing here
     * settles it — that happens only after verify().
     */
    public function initiate(string $orderUuid): PaymentInitiation;

    /**
     * Asks the gateway, server-to-server, for the true state of a payment by
     * its reference. This is the only authority on whether an order is paid —
     * the browser return query and the webhook body are never trusted.
     */
    public function verify(string $reference): PaymentResult;

    /**
     * Whether an incoming background notification is authentic, by the driver's
     * own scheme (Comgate: a shared secret in the body). A first gate only — a
     * pass still leads to verify(), never to trusting the body's own status.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyNotification(array $payload): bool;

    /**
     * Extracts the gateway transaction reference from a notification body, so
     * the webhook can find the order without the controller knowing the
     * driver's field names. Null when the body carries none.
     *
     * @param  array<string, mixed>  $payload
     */
    public function referenceFromNotification(array $payload): ?string;
}
