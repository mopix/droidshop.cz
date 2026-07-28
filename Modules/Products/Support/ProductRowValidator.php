<?php

namespace Modules\Products\Support;

use App\Core\Tax\TaxRates;
use Illuminate\Support\Carbon;

/**
 * Everything wrong with one row, in Czech, ready to print into the error
 * report a merchant will actually read.
 *
 * Returns a list rather than throwing: one bad row must never stop the run,
 * and a row with three problems should report all three rather than make the
 * merchant fix them one upload at a time.
 */
class ProductRowValidator
{
    public function __construct(private readonly TaxRates $rates) {}

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    public function validate(array $row, bool $creating): array
    {
        $errors = [];
        $type = $row['typ'] ?? '';

        if (! in_array($type, [ProductCsvSchema::TYPE_PRODUCT, ProductCsvSchema::TYPE_VARIANT], true)) {
            return ['Sloupec „typ" musí být „produkt" nebo „varianta".'];
        }

        if ($type === ProductCsvSchema::TYPE_VARIANT) {
            if (trim($row['varianta_rodic_sku'] ?? '') === '') {
                $errors[] = 'Varianta musí mít vyplněné rodičovské SKU.';
            }

            if (trim($row['varianta_hodnoty'] ?? '') === '') {
                $errors[] = 'Varianta musí mít vyplněné hodnoty os, například „Velikost:M|Barva:černá".';
            }
        }

        if ($creating && $type === ProductCsvSchema::TYPE_PRODUCT && trim($row['nazev'] ?? '') === '') {
            $errors[] = 'Název je povinný u nového produktu.';
        }

        $errors = array_merge($errors, $this->validatePrices($row));
        $errors = array_merge($errors, $this->validateEnums($row));

        if (trim($row['dph'] ?? '') !== '' && ! $this->rateExists($row['dph'])) {
            $errors[] = 'Neznámá sazba DPH: '.$row['dph'].'.';
        }

        return array_values($errors);
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function validatePrices(array $row): array
    {
        $errors = [];

        foreach (['cena', 'akcni_cena'] as $column) {
            if (trim($row[$column] ?? '') !== '' && ProductCsvSchema::money($row[$column]) === null) {
                $errors[] = 'Sloupec „'.$column.'" není platná částka: '.$row[$column].'.';
            }
        }

        $price = ProductCsvSchema::money($row['cena'] ?? null);
        $sale = ProductCsvSchema::money($row['akcni_cena'] ?? null);

        if ($price !== null && $sale !== null && $sale >= $price) {
            $errors[] = 'Akční cena musí být nižší než běžná cena.';
        }

        $from = $this->date($row['akce_od'] ?? null);
        $to = $this->date($row['akce_do'] ?? null);

        if ($from === false || $to === false) {
            $errors[] = 'Datum akce není platné, použij formát 2026-08-01.';
        } elseif ($from !== null && $to !== null && $to->lessThanOrEqualTo($from)) {
            $errors[] = 'Konec akce musí být po jejím začátku.';
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function validateEnums(array $row): array
    {
        $errors = [];

        if (trim($row['stav'] ?? '') !== '' && ! isset(ProductCsvSchema::STATUSES[$row['stav']])) {
            $errors[] = 'Neznámý stav: '.$row['stav'].'. Použij koncept, aktivni nebo skryty.';
        }

        if (trim($row['sklad_politika'] ?? '') !== '' && ! isset(ProductCsvSchema::STOCK_POLICIES[$row['sklad_politika']])) {
            $errors[] = 'Neznámá skladová politika: '.$row['sklad_politika'].'.';
        }

        if (trim($row['hmotnost_g'] ?? '') !== '' && ! ctype_digit(trim($row['hmotnost_g']))) {
            $errors[] = 'Hmotnost musí být celé číslo v gramech.';
        }

        if (trim($row['sklad_ks'] ?? '') !== '' && preg_match('/^-?\d+$/', trim($row['sklad_ks'])) !== 1) {
            $errors[] = 'Sklad musí být celé číslo.';
        }

        return $errors;
    }

    /**
     * @return Carbon|null|false false = unparseable
     */
    private function date(?string $raw): Carbon|null|false
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return false;
        }
    }

    private function rateExists(string $percent): bool
    {
        $wanted = (float) str_replace(',', '.', trim($percent));

        return $this->rates->all()->contains(
            fn ($rate) => (float) $rate->percent() === $wanted,
        );
    }
}
