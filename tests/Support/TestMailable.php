<?php

namespace Tests\Support;

use Illuminate\Mail\Mailable;

/**
 * A minimal mailable for kernel mail tests.
 *
 * A named class rather than an anonymous one: PHP refuses to serialize
 * anonymous classes, and every queued job round-trips through serialize()
 * even on the sync driver.
 */
class TestMailable extends Mailable
{
    public function __construct(private readonly string $subjectLine = 'Zpráva') {}

    public function build(): self
    {
        return $this->subject($this->subjectLine)->html('<p>Text.</p>');
    }
}
