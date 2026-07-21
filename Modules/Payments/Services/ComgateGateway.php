<?php

namespace Modules\Payments\Services;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Payments\Contracts\PaymentGateway;
use App\Core\Payments\Contracts\PaymentInitiation;
use App\Core\Payments\Exceptions\GatewayError;
use App\Core\Payments\PaymentResult;
use App\Core\Payments\PaymentStatus;
use Illuminate\Support\Facades\Http;
use Modules\Payments\Jobs\ExpireUnpaidOrder;
use Modules\Payments\Support\ComgateSignature;
use Modules\Payments\Support\GatewayInitiation;
use Modules\Shipping\Models\PaymentMethod;

/**
 * Comgate driver over the e-commerce HTTP-POST protocol (v1.0), spec §16.6.
 *
 * Form-encoded requests to two endpoints — /create and /status. Credentials
 * (merchant id, secret, test flag) are the current tenant's, read by the
 * registry from the encrypted payment_methods.settings; this class never reads
 * config or .env for them, because in a multi-tenant shop each shop pays into
 * its own Comgate account.
 *
 * The return and notification URLs are configured once in the tenant's Comgate
 * merchant admin (onboarding), not sent per request — so a payment started
 * here always comes back to /platba/navrat and /platba/notifikace on the
 * tenant's own host.
 */
final class ComgateGateway implements PaymentGateway
{
    /**
     * @param  array{base_url: string, timeout: int}  $config
     */
    public function __construct(
        private readonly string $merchant,
        private readonly string $secret,
        private readonly bool $test,
        private readonly OrderBook $orders,
        private readonly array $config,
    ) {}

    public function provider(): string
    {
        return PaymentMethod::PROVIDER_COMGATE;
    }

    public function initiate(string $orderUuid): PaymentInitiation
    {
        $order = $this->orders->findForAdmin($orderUuid);

        if ($order === null) {
            throw GatewayError::orderMissing($orderUuid);
        }

        // Where the shopper comes back to, carrying our own order uuid so the
        // return controller knows which order without trusting a gateway param.
        // All three outcomes land on the same page; it re-verifies the real
        // status regardless of which URL Comgate chose.
        $returnUrl = route('storefront.payments.return', ['order' => $orderUuid]);

        $response = $this->post('create', [
            'price' => (string) $order->orderTotal()->amount,
            'curr' => $order->orderCurrency(),
            // Comgate caps the label at 16 characters; the order number is both
            // short enough and what a shopper recognises on their statement.
            'label' => substr($order->orderNumber(), 0, 16),
            'refId' => $order->orderNumber(),
            'email' => $order->orderEmail(),
            'method' => 'ALL',
            'url_paid' => $returnUrl,
            'url_pending' => $returnUrl,
            'url_cancelled' => $returnUrl,
            // prepareOnly returns the redirect URL instead of a 302, so we own
            // the redirect and can drop the cart cookie first.
            'prepareOnly' => 'true',
        ]);

        $redirect = $response['redirect'] ?? null;
        $transId = $response['transId'] ?? null;

        if (! is_string($redirect) || $redirect === '' || ! is_string($transId) || $transId === '') {
            throw GatewayError::malformedResponse('create', $response);
        }

        // Starting a payment starts a clock: if it is never paid, an expiry job
        // fails the order and returns its stock.
        $this->scheduleExpiry($orderUuid);

        return new GatewayInitiation($redirect, $transId);
    }

    public function verify(string $reference): PaymentResult
    {
        $response = $this->post('status', [
            'transId' => $reference,
        ]);

        $status = match ((string) ($response['status'] ?? '')) {
            'PAID' => PaymentStatus::Paid,
            'CANCELLED' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };

        // The gateway's own figure. The caller compares it against the order
        // total before settling — a paid result for the wrong amount is not a
        // paid order.
        $amount = new Money(
            (int) ($response['price'] ?? 0),
            (string) ($response['curr'] ?? 'CZK'),
        );

        return new PaymentResult($status, $reference, $amount);
    }

    private function scheduleExpiry(string $orderUuid): void
    {
        // A delayed job needs a real queue. On the sync driver a delayed
        // dispatch runs immediately, which would fail the order the instant it
        // was placed — so there, expiry is left to a manual cancel (which
        // returns stock too). Documented as a known limitation.
        if (config('queue.default') === 'sync') {
            return;
        }

        ExpireUnpaidOrder::dispatch($orderUuid)
            ->delay(now()->addMinutes((int) config('payments.reservation_ttl_minutes')));
    }

    public function verifyNotification(array $payload): bool
    {
        $secret = $payload['secret'] ?? null;

        return ComgateSignature::matches(is_string($secret) ? $secret : null, $this->secret);
    }

    public function referenceFromNotification(array $payload): ?string
    {
        $transId = $payload['transId'] ?? null;

        return (is_string($transId) && $transId !== '') ? $transId : null;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function post(string $endpoint, array $fields): array
    {
        $response = Http::asForm()
            ->timeout($this->config['timeout'])
            ->post($this->config['base_url'].'/'.$endpoint, array_merge($fields, [
                'merchant' => $this->merchant,
                'secret' => $this->secret,
                'test' => $this->test ? 'true' : 'false',
            ]));

        if ($response->failed()) {
            throw GatewayError::transport($endpoint, $response->status());
        }

        // The protocol answers form-urlencoded (code=0&message=OK&…), not JSON.
        parse_str($response->body(), $parsed);

        if (($parsed['code'] ?? null) !== '0') {
            throw GatewayError::rejected($endpoint, (string) ($parsed['code'] ?? '?'), (string) ($parsed['message'] ?? ''));
        }

        /** @var array<string, string> $parsed */
        return $parsed;
    }
}
