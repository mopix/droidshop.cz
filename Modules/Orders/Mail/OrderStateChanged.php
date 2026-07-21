<?php

namespace Modules\Orders\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies the customer that an admin moved their order to a new state
 * (fulfillment or payment).
 *
 * Sent through App\Core\Mail\Contracts\MailService only, never Mail::send()
 * directly (spec §15.1). Only ever queued when the admin explicitly ticked
 * "poslat e-mail zákazníkovi" on the state-change form — this is not the
 * automatic order-confirmation mail (Modules\Orders\Mail\OrderPlacedCustomer),
 * it is an optional, admin-triggered notice.
 */
class OrderStateChanged extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $shopName,
        public readonly string $orderNumber,
        public readonly string $statusLabel,
        public readonly ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Změna stavu objednávky č. '.$this->orderNumber,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'orders::mail.order-state-changed',
        );
    }
}
