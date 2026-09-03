<?php

namespace Modules\Reviews\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent only through App\Core\Mail\Contracts\MailService, never Mail::send()
 * — that is what makes it count against the plan, land in the tenant's mail
 * log and go out under the shop's name.
 *
 * envelope()/content() must be side-effect free: MailService builds the
 * mailable once to read the subject for the log, and the queued job builds
 * it again at delivery.
 *
 * Plain HTML view rather than a markdown mailable, matching every other
 * tenant-facing mailable sent through MailService (OrderPlacedCustomer,
 * Customers' reset-password, Docs' document-issued) — `x-mail::message`
 * markdown components are only used by the platform's own billing notices,
 * which never go through a tenant's sender identity.
 */
class ReviewInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $shopName,
        public readonly string $reviewUrl,
        public readonly string $optoutUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Jak jste byli spokojeni s nákupem?');
    }

    public function content(): Content
    {
        return new Content(view: 'reviews::mail.invitation');
    }
}
