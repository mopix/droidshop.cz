<?php

namespace App\Core\Money;

use App\Core\Money\Exceptions\CurrencyMismatch;
use NumberFormatter;

/**
 * A monetary amount as an integer number of minor units (spec §15.1).
 *
 * Every price in the system is a Money. The amount is haléře, never a float:
 * float arithmetic drifts, and on money that drift is unrecoverable once it
 * has been invoiced.
 */
readonly class Money
{
    public string $currency;

    public function __construct(public int $amount, string $currency)
    {
        $this->currency = strtoupper($currency);
    }

    /**
     * From major units (koruny). The float describes input only; what is
     * stored is an integer. round() guards against the classic 0.1 + 0.2
     * float representation error.
     */
    public static function fromMajor(int|float $major, string $currency): self
    {
        return new self((int) round($major * 100), $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function times(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /**
     * Splits into n equal parts without losing a minor unit.
     *
     * The remainder is handed out one unit at a time to the earliest buckets,
     * so the parts always sum back to the original.
     *
     * @return list<self>
     */
    public function allocate(int $n): array
    {
        return $this->allocateByRatios(array_fill(0, $n, 1));
    }

    /**
     * Splits by integer ratios, remainder to the earliest buckets.
     *
     * @param  list<int>  $ratios
     * @return list<self>
     */
    public function allocateByRatios(array $ratios): array
    {
        $total = array_sum($ratios);
        $remainder = $this->amount;
        $parts = [];

        foreach ($ratios as $ratio) {
            $share = intdiv($this->amount * $ratio, $total);
            $parts[] = $share;
            $remainder -= $share;
        }

        // Distribute whatever integer division left behind.
        for ($i = 0; $remainder > 0; $i++, $remainder--) {
            $parts[$i % count($parts)]++;
        }

        return array_map(fn (int $amount) => new self($amount, $this->currency), $parts);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function format(?string $locale = null): string
    {
        $formatter = new NumberFormatter($locale ?? 'cs_CZ', NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($this->amount / 100, $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
