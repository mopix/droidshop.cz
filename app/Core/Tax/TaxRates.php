<?php

namespace App\Core\Tax;

use App\Core\Tax\Exceptions\UnknownTaxRate;
use App\Models\TaxRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The platform's VAT rate registry (spec §6.2, §15.1).
 *
 * Modules ask this service; none of them owns the list. A rate quoted by the
 * products module has to be the same object the order, the invoice and the
 * shipping fee quote, and a module that could be switched off must not be
 * able to take the rate table with it.
 */
class TaxRates
{
    private const CACHE_KEY = 'tax:rates';

    /**
     * Long TTL: rates change by act of parliament, not by user action, and
     * every write path here calls flush().
     */
    private const CACHE_TTL = 86400;

    /**
     * @return Collection<string, TaxRate>
     */
    public function all(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL,
            fn () => TaxRate::query()->orderBy('position')->get()->keyBy('code')
        );
    }

    public function find(string $code): TaxRate
    {
        $rate = $this->all()->get($code);

        if ($rate === null) {
            // Never fall back to the standard rate. A typo that silently
            // charges 21 % is worse than a failed request.
            throw UnknownTaxRate::code($code);
        }

        return $rate;
    }

    public function findById(int $id): TaxRate
    {
        $rate = $this->all()->firstWhere('id', $id);

        if ($rate === null) {
            throw UnknownTaxRate::id($id);
        }

        return $rate;
    }

    public function default(): TaxRate
    {
        $rate = $this->all()->firstWhere('is_default', true) ?? $this->all()->first();

        if ($rate === null) {
            throw UnknownTaxRate::noneConfigured();
        }

        return $rate;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
