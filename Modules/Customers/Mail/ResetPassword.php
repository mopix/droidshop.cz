<?php

namespace Modules\Customers\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent through App\Core\Mail\Contracts\MailService only, never Mail::send()
 * directly (spec §15.1) — that is what stamps the shop's own name as sender,
 * logs the message, and counts it against the tenant's plan.
 *
 * Built with a plain, already-absolute URL rather than a token/email pair:
 * envelope()/content() must be side-effect free (MailService calls them
 * twice — once to read the subject for the log row, once to actually
 * deliver), and by the time this class exists the controller has already
 * resolved the link against the tenant's own host, which a queue worker
 * running this later could not do for itself.
 */
class ResetPassword extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $resetUrl,
        public readonly string $shopName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Obnovení hesla',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'customers::mail.reset-password',
        );
    }
}
