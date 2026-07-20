<?php

namespace App\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * One VAT rate from the kernel registry (spec §6.2).
 *
 * The conversion methods live here rather than on Money on purpose: Money is
 * the kernel's most primitive value type and must not learn about tax. The
 * dependency points this way round, never back.
 */
class TaxRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate_permille' => 'integer',
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function percent(): float
    {
        return $this->rate_permille / 10;
    }

    /**
     * The amount without VAT, given the amount with VAT.
     *
     * Rounded to whole haléře. Shops quote gross prices, so gross is the
     * stored figure and net is derived — the other way round the price on the
     * shelf would drift by a haléř from what the customer is charged.
     */
    public function net(Money $gross): Money
    {
        if ($this->rate_permille === 0) {
            return $gross;
        }

        $divisor = 1 + ($this->rate_permille / 1000);

        return new Money((int) round($gross->amount / $divisor), $gross->currency);
    }

    public function gross(Money $net): Money
    {
        if ($this->rate_permille === 0) {
            return $net;
        }

        $multiplier = 1 + ($this->rate_permille / 1000);

        return new Money((int) round($net->amount * $multiplier), $net->currency);
    }

    /**
     * The VAT part of a gross amount.
     *
     * Deliberately gross - net, not net * rate: this is what guarantees the
     * two parts add back up to the amount actually charged, whatever the
     * rounding did.
     */
    public function vat(Money $gross): Money
    {
        return $gross->minus($this->net($gross));
    }
}
