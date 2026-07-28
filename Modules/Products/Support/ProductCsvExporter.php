<?php

namespace Modules\Products\Support;

use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;

/**
 * The catalogue as import-shaped rows.
 *
 * A generator, not an array: a shop with 20 000 products must not buffer its
 * whole catalogue in memory to download it (same reason VatCsvWriter streams).
 *
 * Text columns are neutralised against CSV formula injection (CWE-1236);
 * money columns deliberately are not, because a leading quote would turn the
 * figure into text and break the merchant's own SUM().
 */
class ProductCsvExporter
{
    /**
     * @return iterable<array<int, string>>
     */
    public function rows(bool $includeCosts): iterable
    {
        $columns = ProductCsvSchema::COLUMNS;

        if ($includeCosts) {
            $columns[] = ProductCsvSchema::COLUMN_PURCHASE_PRICE;
        }

        yield $columns;

        $statuses = array_flip(ProductCsvSchema::STATUSES);
        $policies = array_flip(ProductCsvSchema::STOCK_POLICIES);

        // lazy(), not chunk(): a closure passed to chunk() is not a generator,
        // so a yield inside it would silently produce nothing.
        foreach (Product::query()
            ->with(['variants.optionValues.option', 'categories', 'manufacturer'])
            ->orderBy('id')
            ->lazy(200) as $product) {
            yield $this->productRow($product, $columns, $statuses, $policies, $includeCosts);

            foreach ($product->variants as $variant) {
                $variant->setRelation('product', $product);

                yield $this->variantRow($product, $variant, $columns);
            }
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $statuses
     * @param  array<string, string>  $policies
     * @return array<int, string>
     */
    private function productRow(
        Product $product,
        array $columns,
        array $statuses,
        array $policies,
        bool $includeCosts,
    ): array {
        $values = [
            'typ' => ProductCsvSchema::TYPE_PRODUCT,
            'sku' => (string) $product->sku,
            'varianta_rodic_sku' => '',
            'varianta_hodnoty' => '',
            'nazev' => $product->name,
            'slug' => $product->slug,
            'stav' => $statuses[$product->status] ?? '',
            'cena' => ProductCsvSchema::formatMoney($product->price->amount),
            'akcni_cena' => $product->sale_price === null
                ? ''
                : ProductCsvSchema::formatMoney($product->sale_price->amount),
            'akce_od' => $product->sale_starts_at?->format('Y-m-d H:i') ?? '',
            'akce_do' => $product->sale_ends_at?->format('Y-m-d H:i') ?? '',
            'dph' => (string) $product->rate()->percent(),
            'ean' => (string) $product->ean,
            'hmotnost_g' => (string) $product->weight_g,
            'sklad_sleduje' => $product->stock_tracked ? 'ano' : 'ne',
            'sklad_ks' => (string) $product->stock_qty,
            'sklad_politika' => $policies[$product->stock_policy] ?? '',
            'kategorie' => $product->categories->map(fn ($category) => $category->name)->implode('|'),
            'vyrobce' => (string) $product->manufacturer?->name,
            'kratky_popis' => (string) $product->short_description,
            'popis' => (string) $product->description,
            'seo_titulek' => (string) $product->seo_title,
            'seo_popis' => (string) $product->seo_description,
        ];

        if ($includeCosts) {
            $values[ProductCsvSchema::COLUMN_PURCHASE_PRICE] = $product->purchase_price === null
                ? ''
                : ProductCsvSchema::formatMoney($product->purchase_price->amount);
        }

        return $this->order($values, $columns);
    }

    /**
     * @param  list<string>  $columns
     * @return array<int, string>
     */
    private function variantRow(Product $product, ProductVariant $variant, array $columns): array
    {
        $axes = $variant->optionValues
            ->sortBy(fn ($value) => $value->option->position)
            ->map(fn ($value) => $value->option->name.':'.$value->value)
            ->implode('|');

        $values = array_fill_keys($columns, '');
        $values['typ'] = ProductCsvSchema::TYPE_VARIANT;
        $values['sku'] = (string) $variant->sku;
        $values['varianta_rodic_sku'] = (string) $product->sku;
        $values['varianta_hodnoty'] = $axes;
        $values['cena'] = ProductCsvSchema::formatMoney($variant->regularPrice()->amount);
        $values['akcni_cena'] = $variant->sale_price === null
            ? ''
            : ProductCsvSchema::formatMoney($variant->sale_price->amount);
        $values['ean'] = (string) $variant->ean;
        $values['sklad_sleduje'] = $variant->stock_tracked ? 'ano' : 'ne';
        $values['sklad_ks'] = (string) $variant->stock_qty;

        return $this->order($values, $columns);
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $columns
     * @return array<int, string>
     */
    private function order(array $values, array $columns): array
    {
        $money = ['cena', 'akcni_cena', ProductCsvSchema::COLUMN_PURCHASE_PRICE];

        return array_map(function (string $column) use ($values, $money) {
            $value = $values[$column] ?? '';

            return in_array($column, $money, true) ? $value : $this->neutralize($value);
        }, $columns);
    }

    private function neutralize(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
