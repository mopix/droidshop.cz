<?php

namespace Tests\Unit\Money;

use App\Core\Money\Exceptions\CurrencyMismatch;
use App\Core\Money\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_built_from_minor_units(): void
    {
        $money = new Money(49900, 'CZK');

        $this->assertSame(49900, $money->amount);
        $this->assertSame('CZK', $money->currency);
    }

    public function test_built_from_major_units(): void
    {
        $money = Money::fromMajor(499, 'CZK');

        $this->assertSame(49900, $money->amount);
    }

    public function test_from_major_accepts_fractional_major(): void
    {
        // 499.90 Kč = 49990 haléřů. The float only ever describes input; the
        // stored value is an integer.
        $money = Money::fromMajor(499.90, 'CZK');

        $this->assertSame(49990, $money->amount);
    }

    public function test_addition(): void
    {
        $sum = (new Money(100, 'CZK'))->plus(new Money(250, 'CZK'));

        $this->assertSame(350, $sum->amount);
    }

    public function test_subtraction(): void
    {
        $diff = (new Money(500, 'CZK'))->minus(new Money(150, 'CZK'));

        $this->assertSame(350, $diff->amount);
    }

    public function test_multiplication_by_quantity(): void
    {
        $total = (new Money(1990, 'CZK'))->times(3);

        $this->assertSame(5970, $total->amount);
    }

    public function test_adding_different_currencies_throws(): void
    {
        $this->expectException(CurrencyMismatch::class);

        (new Money(100, 'CZK'))->plus(new Money(100, 'EUR'));
    }

    public function test_allocation_loses_no_minor_unit(): void
    {
        // 100 haléřů split three ways must stay 100, not 99. The odd unit goes
        // to the earliest bucket. This is the classic accounting rounding trap.
        $parts = (new Money(100, 'CZK'))->allocate(3);

        $this->assertSame([34, 33, 33], array_map(fn (Money $m) => $m->amount, $parts));
        $this->assertSame(100, array_sum(array_map(fn (Money $m) => $m->amount, $parts)));
    }

    public function test_allocation_by_ratios(): void
    {
        // VAT split 21:79 on 100 must still sum to 100.
        $parts = (new Money(100, 'CZK'))->allocateByRatios([21, 79]);

        $this->assertSame(100, array_sum(array_map(fn (Money $m) => $m->amount, $parts)));
    }

    public function test_comparison(): void
    {
        $a = new Money(100, 'CZK');
        $b = new Money(250, 'CZK');

        $this->assertTrue($a->lessThan($b));
        $this->assertTrue($b->greaterThan($a));
        $this->assertTrue($a->equals(new Money(100, 'CZK')));
        $this->assertFalse($a->equals(new Money(100, 'EUR')));
    }

    public function test_zero_and_sign(): void
    {
        $this->assertTrue((new Money(0, 'CZK'))->isZero());
        $this->assertTrue((new Money(-5, 'CZK'))->isNegative());
        $this->assertTrue((new Money(5, 'CZK'))->isPositive());
    }

    public function test_formatting_in_czk(): void
    {
        $formatted = (new Money(149900, 'CZK'))->format();

        // 1 499,00 Kč — normalise spaces since the locale may use NBSP.
        $normalised = preg_replace('/\s+/u', ' ', $formatted);
        $this->assertStringContainsString('1 499,00', $normalised);
        $this->assertStringContainsString('Kč', $normalised);
    }

    public function test_currency_is_normalised_to_upper_case(): void
    {
        $this->assertSame('CZK', (new Money(1, 'czk'))->currency);
    }
}
