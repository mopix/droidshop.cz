<?php

namespace Modules\Customers\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent through App\Core\Mail\Contracts\MailService only, never Mail::send()
 * directly (spec §15.1). Built with a plain, already-absolute URL rather
 * than a token/email pair — see Modules\Customers\Mail\ResetPassword's
 * docblock for why: envelope()/content() must stay side-effect free, and the
 * link has to be resolved against the tenant's own host before this class
 * is ever queued.
 */
class VerifyEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $verifyUrl,
        public readonly string $shopName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Potvrďte svou e-mailovou adresu',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'customers::mail.verify-email',
        );
    }
}
