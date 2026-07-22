<?php

namespace Modules\Docs\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The issued invoice PDF, e-mailed to the customer.
 *
 * Sent through App\Core\Mail\Contracts\MailService only, never Mail::send()
 * directly (spec §15.1) — same reasoning as Orders\Mail\OrderPlacedCustomer.
 * As transactional mail it is never blocked by an exhausted quota (MailKind).
 *
 * Carries plain, already-resolved values rather than the Document model, and
 * the already-rendered PDF rather than a storage path: envelope()/content()/
 * attachments() must be side-effect free (MailService reads them once for the
 * log row and again at delivery), and a module never leaks another module's
 * Eloquent model across this boundary.
 *
 * $pdfBytes is base64-encoded, not raw: this mailable travels through
 * SendTenantMail (ShouldQueue), and even the sync queue driver JSON-encodes
 * the job payload — raw binary PDF bytes are not valid UTF-8 and blow up
 * json_encode() well before delivery. Base64 keeps the payload plain ASCII;
 * attachments() decodes it back on the way out.
 */
class InvoiceIssued extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $shopName,
        public readonly string $invoiceNumber,
        public readonly string $orderNumber,
        public readonly string $total,
        public readonly string $pdfBytes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Faktura č. '.$this->invoiceNumber,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'docs::mail.invoice-issued',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => base64_decode($this->pdfBytes), 'faktura-'.$this->invoiceNumber.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
