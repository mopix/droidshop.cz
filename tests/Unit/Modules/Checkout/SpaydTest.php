<?php

namespace Tests\Unit\Modules\Checkout;

use App\Core\Money\Money;
use Modules\Checkout\Support\Spayd;
use PHPUnit\Framework\TestCase;

/**
 * The SPAYD string carries real payment instructions, so its exact shape is
 * locked here: a wrong amount or variable symbol is a mispaid order.
 */
class SpaydTest extends TestCase
{
    public function test_it_builds_a_spayd_string_from_account_amount_and_variable_symbol(): void
    {
        $spayd = Spayd::forBankTransfer(
            'CZ6508000000192000145399',
            new Money(45_000, 'CZK'), // 450,00 Kč
            '2026001',
        );

        $this->assertSame(
            'SPD*1.0*ACC:CZ6508000000192000145399*AM:450.00*CC:CZK*X-VS:2026001*MSG:Objednavka 2026001',
            $spayd,
        );
    }

    public function test_minor_units_become_a_two_decimal_major_amount(): void
    {
        // 5 Kč exactly, and 5,05 Kč — the pad must not drop or misplace zeros.
        $this->assertStringContainsString('*AM:5.00*', Spayd::forBankTransfer('CZ1', new Money(500, 'CZK'), '1'));
        $this->assertStringContainsString('*AM:5.05*', Spayd::forBankTransfer('CZ1', new Money(505, 'CZK'), '1'));
        $this->assertStringContainsString('*AM:1234.50*', Spayd::forBankTransfer('CZ1', new Money(123_450, 'CZK'), '1'));
    }

    public function test_the_variable_symbol_is_reduced_to_at_most_ten_digits(): void
    {
        // Non-digits stripped, then clamped to 10 digits.
        $spayd = Spayd::forBankTransfer('CZ1', new Money(100, 'CZK'), 'AB-123 456 789 0123');

        $this->assertStringContainsString('*X-VS:1234567890*', $spayd);
    }

    public function test_the_message_is_stripped_of_delimiters_and_diacritics(): void
    {
        $spayd = Spayd::forBankTransfer('CZ1', new Money(100, 'CZK'), '7', 'Příliš*žluťoučký');

        // No '*' inside the value (would break field parsing), no diacritics.
        $this->assertStringContainsString('*MSG:Prilis zlutoucky', $spayd);
        // Exactly the field delimiters (SPD*1.0*ACC*AM*CC*X-VS*MSG = 6), none
        // smuggled in by the free-text message.
        $this->assertSame(6, substr_count($spayd, '*'));
    }
}
