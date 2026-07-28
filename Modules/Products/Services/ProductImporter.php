<?php

namespace Modules\Products\Services;

use App\Core\Limits\LimitsService;
use App\Core\Tax\TaxRates;
use Illuminate\Support\Facades\DB;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Support\ProductCsvSchema;
use Modules\Products\Support\ProductRowValidator;
use RuntimeException;

/**
 * Applies one CSV row to the catalogue.
 *
 * One row, one transaction: a run of 3 000 rows must not hold locks over the
 * whole catalogue, and a crash halfway must not roll back an hour of work.
 *
 * Everything goes through ProductWriter/VariantWriter — never Product::create
 * — so an import gets the same HTML sanitising, unique slug, 301 redirect and
 * price-history entry (wave 2.7) as a merchant typing into the admin.
 */
class ProductImporter
{
    public function __construct(
        private readonly ProductWriter $products,
        private readonly VariantWriter $variants,
        private readonly ProductRowValidator $validator,
        private readonly LimitsService $limits,
        private readonly TaxRates $rates,
    ) {}

    /**
     * @param  array<string, string>  $row
     * @return list<string> empty when the row applied
     */
    public function import(array $row, bool $dryRun): array
    {
        if (($row['typ'] ?? '') === ProductCsvSchema::TYPE_VARIANT) {
            return $this->importVariant($row, $dryRun);
        }

        $sku = trim($row['sku'] ?? '');
        $existing = null;

        if ($sku !== '') {
            $matches = Product::query()->where('sku', $sku)->get();

            if ($matches->count() > 1) {
                return ['Více produktů sdílí SKU '.$sku.', import neví, který aktualizovat.'];
            }

            $existing = $matches->first();
        }

        $errors = $this->validator->validate($row, creating: $existing === null);

        if ($errors !== []) {
            return $errors;
        }

        if ($existing === null) {
            // allowed() is a method and message already carries the Czech
            // sentence the admin shows elsewhere — no second wording of the
            // same rule (App\Core\Limits\LimitResult).
            $limit = $this->limits->check('products');

            if (! $limit->allowed()) {
                return [$limit->message];
            }
        }

        try {
            $categoryIds = $this->resolveCategories($row['kategorie'] ?? '');
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }

        if ($dryRun) {
            return [];
        }

        DB::transaction(function () use ($row, $existing, $categoryIds): void {
            $attributes = $this->attributes($row, creating: $existing === null);

            $product = $existing === null
                ? $this->products->create($attributes)
                : $this->products->update($existing, $attributes);

            if ($categoryIds !== []) {
                $this->products->syncCategories($product, $categoryIds, $categoryIds[0]);
            }
        });

        return [];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function importVariant(array $row, bool $dryRun): array
    {
        $errors = $this->validator->validate($row, creating: true);

        if ($errors !== []) {
            return $errors;
        }

        $parentSku = trim($row['varianta_rodic_sku']);
        $parents = Product::query()->where('sku', $parentSku)->get();

        if ($parents->count() > 1) {
            return ['Více produktů sdílí SKU '.$parentSku.', varianta neví, kam patří.'];
        }

        $parent = $parents->first();

        if ($parent === null) {
            return ['Rodičovský produkt se SKU '.$parentSku.' v katalogu není.'];
        }

        $axes = $this->parseAxes($row['varianta_hodnoty']);

        if ($axes === []) {
            return ['Hodnoty os varianty nejdou přečíst, čekaný tvar je „Velikost:M|Barva:černá".'];
        }

        if ($dryRun) {
            return [];
        }

        $this->variants->upsertVariant($parent, $axes, array_filter([
            'sku' => trim($row['sku'] ?? '') ?: null,
            'ean' => trim($row['ean'] ?? '') ?: null,
            'price' => ProductCsvSchema::money($row['cena'] ?? null),
            'sale_price' => ProductCsvSchema::money($row['akcni_cena'] ?? null),
            'stock_tracked' => ProductCsvSchema::bool($row['sklad_sleduje'] ?? null),
            'stock_qty' => trim($row['sklad_ks'] ?? '') === '' ? null : (int) $row['sklad_ks'],
            'stock_policy' => ProductCsvSchema::STOCK_POLICIES[$row['sklad_politika'] ?? ''] ?? null,
        ], fn ($value) => $value !== null));

        return [];
    }

    /**
     * Only the cells the row actually filled in: an empty cell on an update
     * means "leave it alone", not "erase it". A blank column in a spreadsheet
     * would otherwise wipe the descriptions of a whole catalogue.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function attributes(array $row, bool $creating): array
    {
        $map = [
            'nazev' => fn (string $v) => ['name' => $v],
            'slug' => fn (string $v) => ['slug' => $v],
            'stav' => fn (string $v) => ['status' => ProductCsvSchema::STATUSES[$v]],
            'cena' => fn (string $v) => ['price' => ProductCsvSchema::money($v)],
            'akcni_cena' => fn (string $v) => ['sale_price' => ProductCsvSchema::money($v)],
            'akce_od' => fn (string $v) => ['sale_starts_at' => $v],
            'akce_do' => fn (string $v) => ['sale_ends_at' => $v],
            'ean' => fn (string $v) => ['ean' => $v],
            'hmotnost_g' => fn (string $v) => ['weight_g' => (int) $v],
            'sklad_sleduje' => fn (string $v) => ['stock_tracked' => ProductCsvSchema::bool($v)],
            'sklad_ks' => fn (string $v) => ['stock_qty' => (int) $v],
            'sklad_politika' => fn (string $v) => ['stock_policy' => ProductCsvSchema::STOCK_POLICIES[$v]],
            'kratky_popis' => fn (string $v) => ['short_description' => $v],
            'popis' => fn (string $v) => ['description' => $v],
            'seo_titulek' => fn (string $v) => ['seo_title' => $v],
            'seo_popis' => fn (string $v) => ['seo_description' => $v],
            'sku' => fn (string $v) => ['sku' => $v],
        ];

        $attributes = [];

        foreach ($map as $column => $transform) {
            $value = trim($row[$column] ?? '');

            if ($value !== '') {
                $attributes = array_merge($attributes, $transform($value));
            }
        }

        if (trim($row['dph'] ?? '') !== '') {
            $attributes['tax_rate_id'] = $this->rateId($row['dph']);
        } elseif ($creating) {
            $attributes['tax_rate_id'] = $this->rates->default()->id;
        }

        if (trim($row['vyrobce'] ?? '') !== '') {
            $attributes['manufacturer_id'] = $this->products->manufacturer(trim($row['vyrobce']))->id;
        }

        return $attributes;
    }

    /**
     * @return list<int>
     *
     * @throws RuntimeException when a path does not exist
     */
    private function resolveCategories(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $ids = [];

        foreach (explode('|', $raw) as $path) {
            $parentId = null;
            $category = null;

            foreach (array_map('trim', explode('>', $path)) as $name) {
                if ($name === '') {
                    continue;
                }

                $category = Category::query()
                    ->where('name', $name)
                    ->where('parent_id', $parentId)
                    ->first();

                // Deliberately not created: categories are a shared tree, and
                // a typo in one of 3 000 rows would leave a branch nobody
                // asked for that a merchant then has to clean up by hand.
                if ($category === null) {
                    throw new RuntimeException('Kategorie „'.trim($path).'" v e-shopu neexistuje.');
                }

                $parentId = $category->id;
            }

            if ($category !== null) {
                $ids[] = (int) $category->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, string> axis name => value
     */
    private function parseAxes(string $raw): array
    {
        $axes = [];

        foreach (explode('|', $raw) as $pair) {
            $parts = explode(':', $pair, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);

            if ($name !== '' && $value !== '') {
                $axes[$name] = $value;
            }
        }

        return $axes;
    }

    private function rateId(string $percent): int
    {
        $wanted = (float) str_replace(',', '.', trim($percent));

        return $this->rates->all()
            ->first(fn ($rate) => (float) $rate->percent() === $wanted)
            ->id;
    }
}
