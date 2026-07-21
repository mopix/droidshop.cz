<?php

namespace Modules\Checkout\Http\Controllers;

use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\Contracts\OrderView;
use App\Core\Shipping\Contracts\PaymentOptions;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Checkout\Support\Spayd;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Storefront\Support\Seo;

/**
 * `/dekujeme/{uuid}` — the order confirmation.
 *
 * Public (a guest order has no login), but it must never leak someone else's
 * order. The only capability is the uuid itself: the order is resolved
 * strictly through OrderPlacement::find($uuid), which is tenant-scoped, so a
 * foreign or guessed uuid resolves to nothing and 404s. The page shows only
 * what confirms the order — number, totals, item list, payment instruction —
 * not the customer's stored contact details.
 *
 * For a bank transfer it renders a SPAYD QR as inline SVG (endroid/qr-code
 * SvgWriter — no GD). The pay-to account is a payment instruction the customer
 * acts on, not a withheld secret; it is re-read live from the payment method,
 * never from the order snapshot, which holds no credential (spec §16.5).
 */
class ThankYouController
{
    public function __construct(
        private readonly OrderPlacement $orders,
        private readonly PaymentOptions $payments,
    ) {}

    public function show(Request $request, string $uuid): Response
    {
        $order = $this->orders->find($uuid);

        if (! $order instanceof OrderView) {
            abort(404);
        }

        $snapshot = $order->orderPaymentSnapshot() ?? [];
        $paymentLabel = (string) ($snapshot['name'] ?? 'Osobní odběr');

        [$qrSvg, $account] = $this->bankTransfer($order, $snapshot);

        $view = view('checkout::thank-you', [
            'order' => $order,
            'paymentLabel' => $paymentLabel,
            'qrSvg' => $qrSvg,
            'bankAccount' => $account,
            'variableSymbol' => $order->orderNumber(),
            'seo' => new Seo(title: 'Děkujeme za objednávku', noindex: true),
        ]);

        return response($view)->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    /**
     * The SPAYD QR (inline SVG) and pay-to account for a bank-transfer order,
     * or [null, null] for any other method. The account and provider are
     * re-resolved from the live payment method — the order snapshot keeps
     * neither the provider nor the account.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{0: ?string, 1: ?string}
     */
    private function bankTransfer(OrderView $order, array $snapshot): array
    {
        $paymentId = $snapshot['id'] ?? null;

        if ($paymentId === null) {
            return [null, null];
        }

        $method = $this->payments->find((int) $paymentId);

        if ($method === null || $method->provider() !== PaymentMethod::PROVIDER_BANK_TRANSFER) {
            return [null, null];
        }

        $account = $method->spaydAccount();

        if ($account === null) {
            return [null, null];
        }

        $spayd = Spayd::forBankTransfer($account, $order->orderTotal(), $order->orderNumber());

        return [$this->renderSvg($spayd), $account];
    }

    /**
     * Renders a SPAYD string to inline SVG, without the XML prolog SvgWriter
     * prepends — a `<?xml ?>` declaration has no place mid-HTML.
     */
    private function renderSvg(string $data): string
    {
        $svg = (new SvgWriter)->write(new QrCode($data))->getString();

        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    }
}
