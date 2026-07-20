<?php

namespace Tests\Support;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A mailable that declares its subject through envelope(), the shape every
 * `php artisan make:mail` scaffold has produced since Laravel 9.
 *
 * Kept separate from TestMailable (which uses the older build() style) so
 * QueuedMailService::subjectOf()'s envelope() branch — the one every future
 * module's mailables will actually take — has coverage of its own.
 */
class EnvelopeTestMailable extends Mailable
{
    public function __construct(private readonly string $subjectLine = 'Zpráva z obálky') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Text.</p>');
    }
}
