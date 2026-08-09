<?php

namespace Tests\Unit;

use App\Core\Money\Exceptions\InvalidMoneyInput;
use App\Core\Money\MoneyInput;
use PHPUnit\Framework\TestCase;

/**
 * Parsing a price the way a person types it (wave 3.8).
 */
class MoneyInputTest extends TestCase
{
    public function test_a_whole_number_is_korunas(): void
    {
        $this->assertSame(179000, MoneyInput::toMinorUnits('1790'));
        $this->assertSame(179000, MoneyInput::toMinorUnits(1790));
    }

    public function test_both_decimal_separators_are_accepted(): void
    {
        $this->assertSame(179050, MoneyInput::toMinorUnits('1790,50'));
        $this->assertSame(179050, MoneyInput::toMinorUnits('1790.50'));
    }

    /**
     * "1790.5" is fifty haléře, not five. A multiplication by ten would be
     * the obvious way to get this wrong.
     */
    public function test_a_single_decimal_digit_means_tens_of_haleru(): void
    {
        $this->assertSame(179050, MoneyInput::toMinorUnits('1790,5'));
    }

    /**
     * Copying a figure out of a spreadsheet brings its thousands separator
     * along, and Czech formatting uses a non-breaking one.
     */
    public function test_thousands_separators_are_ignored(): void
    {
        $this->assertSame(179050, MoneyInput::toMinorUnits('1 790,50'));
        $this->assertSame(179050, MoneyInput::toMinorUnits("1\u{00A0}790,50"));
        $this->assertSame(179050, MoneyInput::toMinorUnits("1\u{202F}790,50"));
    }

    /**
     * The classic way a price ends up a haléř short: (int) (0.07 * 100) is 6.
     */
    public function test_no_float_arithmetic_reaches_the_result(): void
    {
        $this->assertSame(7, MoneyInput::toMinorUnits('0,07'));
        $this->assertSame(29, MoneyInput::toMinorUnits('0,29'));
        $this->assertSame(115, MoneyInput::toMinorUnits('1,15'));
    }

    /**
     * Empty is not zero. A blank purchase price means "not filled in"; zero
     * means "free", and collapsing the two would give products away.
     */
    public function test_empty_stays_empty(): void
    {
        $this->assertNull(MoneyInput::toMinorUnits(null));
        $this->assertNull(MoneyInput::toMinorUnits(''));
        $this->assertNull(MoneyInput::toMinorUnits('   '));
        $this->assertSame(0, MoneyInput::toMinorUnits('0'));
    }

    public function test_more_than_two_decimals_is_refused(): void
    {
        $this->expectException(InvalidMoneyInput::class);

        MoneyInput::toMinorUnits('1790,555');
    }

    public function test_something_that_is_not_a_number_is_refused(): void
    {
        $this->expectException(InvalidMoneyInput::class);

        MoneyInput::toMinorUnits('1 79O,00'); // letter O, not a zero
    }

    public function test_negative_amounts_survive(): void
    {
        $this->assertSame(-179050, MoneyInput::toMinorUnits('-1790,50'));
    }

    public function test_a_stored_amount_goes_back_into_a_field(): void
    {
        $this->assertSame('1790,50', MoneyInput::toInput(179050));
        $this->assertSame('0,07', MoneyInput::toInput(7));
        $this->assertNull(MoneyInput::toInput(null));
    }

    /**
     * The round trip is what a merchant actually experiences: save, reopen,
     * and the figure must be the one they typed.
     */
    public function test_the_round_trip_is_lossless(): void
    {
        foreach (['0,01', '0,07', '1790,50', '99999,99'] as $typed) {
            $this->assertSame($typed, MoneyInput::toInput(MoneyInput::toMinorUnits($typed)));
        }
    }
}
