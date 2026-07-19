<?php

namespace App\Core\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts an integer minor-unit column plus a currency to a Money and back.
 *
 * Usage: `'price' => MoneyCast::class` reads the currency from a sibling
 * `currency` column, or `MoneyCast::class.':price_currency'` to name it.
 *
 * @implements CastsAttributes<Money, Money>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(private readonly string $currencyColumn = 'currency') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        $currency = $attributes[$this->currencyColumn] ?? config('app.currency', 'CZK');

        return new Money((int) $value, $currency);
    }

    /**
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! $value instanceof Money) {
            // A bare int is taken as minor units in the model's currency, which
            // keeps simple assignments working without constructing a Money.
            $currency = $attributes[$this->currencyColumn] ?? config('app.currency', 'CZK');
            $value = new Money((int) $value, $currency);
        }

        return [
            $key => $value->amount,
            $this->currencyColumn => $value->currency,
        ];
    }
}
