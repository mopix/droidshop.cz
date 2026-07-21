<?php

namespace Modules\Orders\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The order confirmation the customer receives.
 *
 * Sent through App\Core\Mail\Contracts\MailService only, never Mail::send()
 * directly (spec §15.1) — that stamps the shop's own name as sender, logs the
 * message and counts it against the tenant's plan. As transactional mail it
 * is never blocked by an exhausted quota (MailKind).
 *
 * Carries plain, already-resolved values rather than the Order model: the
 * URL is resolved against the tenant's host while the request still has that
 * context, and envelope()/content() must be side-effect free (MailService
 * reads them once for the log row and again at delivery).
 */
class OrderPlacedCustomer extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{name: string, quantity: int, lineTotal: string}>  $lines
     */
    public function __construct(
        public readonly string $shopName,
        public readonly string $orderNumber,
        public readonly string $orderUrl,
        public readonly string $total,
        public readonly array $lines,
        public readonly string $paymentLabel,
        public readonly ?string $paymentInstruction = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Potvrzení objednávky č. '.$this->orderNumber,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'orders::mail.order-placed-customer',
        );
    }
}
