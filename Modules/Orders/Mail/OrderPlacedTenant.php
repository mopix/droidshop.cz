<?php

namespace Modules\Orders\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The "new order" notice the shop operator (nájemce) receives.
 *
 * Same rules as OrderPlacedCustomer: sent only through MailService,
 * transactional, and carrying plain resolved values rather than the Order
 * model. It intentionally holds no payment credential — only what the
 * operator needs to see that an order came in.
 */
class OrderPlacedTenant extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{name: string, quantity: int, lineTotal: string}>  $lines
     */
    public function __construct(
        public readonly string $shopName,
        public readonly string $orderNumber,
        public readonly string $customerName,
        public readonly string $customerEmail,
        public readonly string $total,
        public readonly array $lines,
        public readonly string $paymentLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nová objednávka č. '.$this->orderNumber,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'orders::mail.order-placed-tenant',
        );
    }
}
