<?php

namespace App\Core\Billing\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A paying tenant fell to past_due: the card was declined or the invoice went
 * unpaid. Distinct from TrialExpiredMail, which is the same destination
 * reached from trial and means nothing went wrong.
 */
class PaymentFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Tenant $tenant) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Platbu za předplatné se nepodařilo zpracovat');
    }

    public function content(): Content
    {
        return new Content(markdown: 'billing.mail.payment-failed');
    }
}
