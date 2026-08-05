<?php

namespace App\Core\Billing\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The clock on deletion started. This is the last message where the owner can
 * still act, so it says explicitly that the data is still there and how to
 * stop the deletion.
 */
class PendingDeletionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Tenant $tenant, public string $reason = '') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Váš e-shop je připraven ke smazání');
    }

    public function content(): Content
    {
        return new Content(markdown: 'billing.mail.pending-deletion');
    }
}
