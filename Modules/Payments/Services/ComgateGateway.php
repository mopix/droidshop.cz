<?php

namespace Modules\Payments\Services;

use App\Core\Money\Money;
use App\Core\Orders\Contracts\OrderBook;
use App\Core\Payments\Contracts\PaymentGateway;
use App\Core\Payments\Contracts\PaymentInitiation;
use App\Core\Payments\PaymentResult;
use App\Core\Payments\PaymentStatus;
use Illuminate\Support\Facades\Http;
use Modules\Payments\Exceptions\GatewayError;
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

        $response = $this->post('create', [
            'price' => (string) $order->orderTotal()->amount,
            'curr' => $order->orderCurrency(),
            // Comgate caps the label at 16 characters; the order number is both
            // short enough and what a shopper recognises on their statement.
            'label' => substr($order->orderNumber(), 0, 16),
            'refId' => $order->orderNumber(),
            'email' => $order->orderEmail(),
            'method' => 'ALL',
            // prepareOnly returns the redirect URL instead of a 302, so we own
            // the redirect and can drop the cart cookie first.
            'prepareOnly' => 'true',
        ]);

        $redirect = $response['redirect'] ?? null;
        $transId = $response['transId'] ?? null;

        if (! is_string($redirect) || $redirect === '' || ! is_string($transId) || $transId === '') {
            throw GatewayError::malformedResponse('create', $response);
        }

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
