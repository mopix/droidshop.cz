<?php

namespace Modules\Docs\Exceptions;

use RuntimeException;

/**
 * A credit note corrects an issued invoice, so it may only be raised for an
 * order that has one and has actually been reversed (cancelled or refunded).
 * Thrown from CreditNoteIssuer::build(), surfaced by the admin controller as a
 * 422 — the button is also hidden in that state, this is the defence in depth.
 */
class CreditNoteNotAllowed extends RuntimeException
{
    public static function noInvoice(): self
    {
        return new self('Objednávka nemá vystavenou fakturu, dobropis nelze vystavit.');
    }

    public static function notReversed(): self
    {
        return new self('Dobropis lze vystavit jen ke stornované nebo refundované objednávce.');
    }
}
