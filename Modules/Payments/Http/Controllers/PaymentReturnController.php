<?php

namespace Modules\Payments\Http\Controllers;

use App\Core\Orders\Contracts\OrderBook;
use App\Core\Payments\PaymentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Payments\Services\PaymentSettlement;

/**
 * `GET /platba/navrat` — where the shopper's browser lands back from the
 * gateway (spec §16.6).
 *
 * The `order` query is our own uuid, placed in the return URL at initiation.
 * The order is resolved strictly tenant-scoped and 404s on a foreign or guessed
 * uuid, exactly like the thank-you page. Crucially the outcome shown here comes
 * from PaymentSettlement re-verifying the gateway, never from any query the
 * browser arrived with — a forged `?status=paid` settles nothing.
 */
class PaymentReturnController
{
    public function __construct(
        private readonly OrderBook $orders,
        private readonly PaymentSettlement $settlement,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $order = $this->orders->findForAdmin((string) $request->query('order'));

        abort_if($order === null, 404);

        $status = $this->settlement->settle($order);
        $uuid = $order->orderUuid();

        return match ($status) {
            PaymentStatus::Paid => redirect()->route('storefront.checkout.thankYou', ['uuid' => $uuid]),
            PaymentStatus::Failed => redirect()
                ->route('storefront.checkout.thankYou', ['uuid' => $uuid])
                ->with('status', 'Platba se nezdařila. Objednávku můžete dokončit a zkusit platbu znovu.'),
            PaymentStatus::Pending => redirect()
                ->route('storefront.checkout.thankYou', ['uuid' => $uuid])
                ->with('status', 'Platba se zpracovává. Jakmile ji brána potvrdí, objednávka se označí jako zaplacená.'),
        };
    }
}
