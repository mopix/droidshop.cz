<?php

namespace Modules\Orders\Listeners;

use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Tenancy\TenantContext;
use Modules\Orders\Events\OrderPlaced;
use Modules\Orders\Mail\OrderPlacedCustomer;
use Modules\Orders\Mail\OrderPlacedTenant;
use Modules\Orders\Models\Order;
use Modules\Shipping\Models\PaymentMethod;
use Throwable;

/**
 * Sends the two order confirmations — to the customer and to the shop
 * operator — the instant a new order is placed.
 *
 * Runs synchronously inside the placing request, so it has the tenant context
 * and host it needs to resolve the shop name and the absolute thank-you URL.
 * Everything it hands the mailables is a plain resolved value, not the model,
 * so the queued delivery job carries no scoped Eloquent state.
 *
 * A failure here must never undo a placed order: the transaction has already
 * committed by the time this fires, and a mail hiccup (SMTP down, no operator
 * address) is not a reason to lose the sale. Every send is guarded so the
 * worst case is a missing confirmation, logged, not a 500 on a real order.
 */
class SendOrderConfirmation
{
    public function __construct(
        private readonly MailService $mail,
        private readonly TenantContext $context,
        private readonly PaymentOptions $payments,
    ) {}

    public function handle(OrderPlaced $event): void
    {
        $order = $event->order;
        $tenant = $this->context->current();

        if ($tenant === null) {
            return;
        }

        $shopName = $tenant->name;
        $lines = $this->lines($order);
        $total = $order->total->format();
        $paymentLabel = $this->paymentLabel($order);

        try {
            $this->mail->send(
                new OrderPlacedCustomer(
                    shopName: $shopName,
                    orderNumber: $order->number,
                    orderUrl: route('storefront.checkout.thankYou', ['uuid' => $order->uuid]),
                    total: $total,
                    lines: $lines,
                    paymentLabel: $paymentLabel,
                    paymentInstruction: $this->paymentInstruction($order),
                ),
                $order->email,
                MailKind::Transactional,
                $tenant,
            );
        } catch (Throwable $e) {
            report($e);
        }

        try {
            $operatorEmail = $this->operatorEmail();

            if ($operatorEmail === null) {
                return;
            }

            $this->mail->send(
                new OrderPlacedTenant(
                    shopName: $shopName,
                    orderNumber: $order->number,
                    customerName: $this->customerName($order),
                    customerEmail: $order->email,
                    total: $total,
                    lines: $lines,
                    paymentLabel: $paymentLabel,
                ),
                $operatorEmail,
                MailKind::Transactional,
                $tenant,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return list<array{name: string, quantity: int, lineTotal: string}>
     */
    private function lines(Order $order): array
    {
        return $order->orderItems()
            ->map(fn ($item): array => [
                'name' => (string) $item->name,
                'quantity' => (int) $item->quantity,
                'lineTotal' => $item->line_total->format(),
            ])
            ->all();
    }

    private function paymentLabel(Order $order): string
    {
        $snapshot = $order->payment_snapshot ?? [];

        return (string) ($snapshot['name'] ?? 'Osobní odběr');
    }

    /**
     * A short pay-to note for a bank transfer, or null for any method that
     * needs nothing done in advance (cash on delivery, personal pickup). The
     * account is re-read live from the payment method — never taken from the
     * order snapshot, which deliberately holds no credential (spec §16.5).
     */
    private function paymentInstruction(Order $order): ?string
    {
        $snapshot = $order->payment_snapshot ?? [];
        $paymentId = $snapshot['id'] ?? null;

        if ($paymentId === null) {
            return null;
        }

        $method = $this->payments->find((int) $paymentId);

        if ($method === null || $method->provider() !== PaymentMethod::PROVIDER_BANK_TRANSFER) {
            return null;
        }

        $account = $method->spaydAccount();

        if ($account === null) {
            return null;
        }

        return 'Zaplaťte prosím převodem na účet '.$account.', variabilní symbol '.$order->number.'.';
    }

    private function customerName(Order $order): string
    {
        $billing = $order->billing ?? [];

        return (string) ($billing['name'] ?? $order->email);
    }

    /**
     * Where the shop operator's copy goes: the tenant's own reply-to address
     * if set, otherwise the first tenant member's e-mail. Null when neither
     * exists — the operator copy is simply skipped, the customer's is not.
     */
    private function operatorEmail(): ?string
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            return null;
        }

        if ($tenant->mail_reply_to) {
            return $tenant->mail_reply_to;
        }

        return $tenant->users()->value('users.email');
    }
}
