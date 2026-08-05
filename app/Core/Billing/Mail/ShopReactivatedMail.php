<?php

namespace App\Core\Billing\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A shop that was past_due or suspended is running again. Not sent for
 * trial → active: that first payment already gets its own confirmation from
 * Stripe and a Czech tax document from PlatformInvoiceWriter.
 */
class ShopReactivatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Tenant $tenant) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Váš e-shop je opět aktivní');
    }

    public function content(): Content
    {
        return new Content(markdown: 'billing.mail.shop-reactivated');
    }
}
