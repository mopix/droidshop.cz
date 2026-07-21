<?php

namespace Modules\Orders\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies the customer that their order was cancelled.
 *
 * Sent through App\Core\Mail\Contracts\MailService only, never Mail::send()
 * directly (spec §15.1). Only ever queued when the admin ticked "poslat
 * e-mail" on the storno dialog — a nájemce cancelling a fraudulent or
 * duplicate order has good reason not to notify the customer, so this is
 * opt-in, not automatic.
 */
class OrderCancelled extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $shopName,
        public readonly string $orderNumber,
        public readonly string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Zrušení objednávky č. '.$this->orderNumber,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'orders::mail.order-cancelled',
        );
    }
}
