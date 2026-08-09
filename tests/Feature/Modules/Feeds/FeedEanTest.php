<?php

namespace Tests\Feature\Modules\Feeds;

use Modules\Products\Rules\Ean;
use PHPUnit\Framework\TestCase;

/**
 * What counts as a real barcode (wave 3.12).
 *
 * The check digit is computed from the digits before it, which is why a
 * made-up number almost never passes — and why the feeds can rely on this to
 * keep an internal code out of Heureka and Zboží.cz, where a wrong one either
 * fails to pair or pairs the product with somebody else's listing.
 */
class FeedEanTest extends TestCase
{
    public function test_a_real_barcode_passes(): void
    {
        $this->assertTrue(Ean::isValid('8594001234561'));  // EAN-13
        $this->assertTrue(Ean::isValid('96385074'));       // EAN-8
    }

    public function test_a_made_up_number_does_not(): void
    {
        $this->assertFalse(Ean::isValid('1234567890123'));
        $this->assertFalse(Ean::isValid('12345678901'));   // no such length
        $this->assertFalse(Ean::isValid('ABC'));
        $this->assertFalse(Ean::isValid(null));
    }

    /**
     * What the form offers when everything but the last digit is typed.
     */
    public function test_the_check_digit_can_be_computed(): void
    {
        $this->assertSame(1, Ean::checkDigitFor('859400123456'));
        $this->assertSame(4, Ean::checkDigitFor('9638507'));
        $this->assertNull(Ean::checkDigitFor('12345'));
    }
}
