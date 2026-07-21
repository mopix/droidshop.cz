<?php

namespace Modules\Payments\Http\Controllers;

use App\Core\Orders\Contracts\OrderBook;
use App\Core\Payments\Contracts\PaymentGatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Payments\Services\PaymentSettlement;
use Modules\Shipping\Models\PaymentMethod;

/**
 * `POST /platba/notifikace` — Comgate's server-to-server payment notification
 * (spec §16.6).
 *
 * Outside CSRF (an S2S caller has no token or session); authenticated instead
 * by the driver's own scheme (a shared secret in the body). The tenant is the
 * shop whose host this request arrived on — each tenant configures its own
 * Comgate account with its own shop URL — and the `module:payments` gate has
 * already confirmed the module runs for it. Even so, the body is never trusted
 * for the payment's state: we re-verify through the gateway before settling.
 *
 * Always answers 2xx once handled (including "unknown order, nothing to do") so
 * Comgate stops resending; only an authentic-but-malformed or unauthenticated
 * call gets a 4xx.
 */
class PaymentWebhookController
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly OrderBook $orders,
        private readonly PaymentSettlement $settlement,
    ) {}

    public function __invoke(Request $request): Response
    {
        // One gateway in this wave; a second would key this off the provider,
        // e.g. a path segment. Comgate is the only notifier for now.
        $gateway = $this->gateways->for(PaymentMethod::PROVIDER_COMGATE);

        // Module off or gateway not configured for this tenant: nothing here
        // can be authenticated, so refuse.
        abort_if($gateway === null, 404);

        $payload = $request->all();

        // Authenticity gate. A body without the tenant's secret is not from
        // this shop's gateway.
        abort_unless($gateway->verifyNotification($payload), 403);

        $reference = $gateway->referenceFromNotification($payload);

        abort_if($reference === null, 400);

        $order = $this->orders->findByReference($reference);

        // Authentic notification for an order we do not have (or another
        // tenant's — the scoped read returns null): acknowledge so Comgate
        // stops retrying, but change nothing.
        if ($order !== null) {
            $this->settlement->settle($order);
        }

        return response('OK', 200);
    }
}
