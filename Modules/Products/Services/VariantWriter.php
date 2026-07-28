<?php

namespace Modules\Products\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductOptionValue;
use Modules\Products\Models\ProductVariant;

/**
 * Everything the admin does to a product's variant matrix.
 *
 * The generator is the reason this is a service rather than controller code:
 * regenerating must never touch a combination that already exists, because
 * that row carries the price and the stock a shop has been running on.
 */
class VariantWriter
{
    public function __construct(private readonly PriceHistoryRecorder $history) {}

    public function addOption(Product $product, string $name): ProductOption
    {
        $max = ProductOption::query()->where('product_id', $product->id)->max('position');

        return ProductOption::create([
            'product_id' => $product->id,
            'name' => $name,
            'position' => $max === null ? 0 : (int) $max + 1,
        ]);
    }

    public function renameOption(ProductOption $option, string $name): ProductOption
    {
        // Renaming an axis leaves every variant intact: the link is by id,
        // the name is only ever text.
        $option->update(['name' => $name]);

        return $option;
    }

    public function deleteOption(ProductOption $option): void
    {
        DB::transaction(function () use ($option) {
            // Every variant that named a value of this axis stops being a
            // real combination — the cascade on product_variant_values would
            // leave them as partial rows otherwise.
            $variantIds = DB::table('product_variant_values')
                ->join('product_option_values', 'product_option_values.id', '=', 'product_variant_values.option_value_id')
                ->where('product_option_values.option_id', $option->id)
                ->pluck('product_variant_values.variant_id')
                ->unique()
                ->all();

            ProductVariant::query()->whereIn('id', $variantIds)->delete();
            $option->delete();
        });
    }

    public function addValue(ProductOption $option, string $value): ProductOptionValue
    {
        $max = ProductOptionValue::query()->where('option_id', $option->id)->max('position');

        return ProductOptionValue::create([
            'option_id' => $option->id,
            'value' => $value,
            'position' => $max === null ? 0 : (int) $max + 1,
        ]);
    }

    public function deleteValue(ProductOptionValue $value): void
    {
        DB::transaction(function () use ($value) {
            $variantIds = DB::table('product_variant_values')
                ->where('option_value_id', $value->id)
                ->pluck('variant_id')
                ->all();

            ProductVariant::query()->whereIn('id', $variantIds)->delete();
            $value->delete();
        });
    }

    public function moveOption(ProductOption $option, int $direction): void
    {
        $this->swapPosition(
            ProductOption::query()->where('product_id', $option->product_id)->orderBy('position')->get(),
            $option,
            $direction,
        );
    }

    public function moveValue(ProductOptionValue $value, int $direction): void
    {
        $this->swapPosition(
            ProductOptionValue::query()->where('option_id', $value->option_id)->orderBy('position')->get(),
            $value,
            $direction,
        );
    }

    /**
     * Creates every combination that does not exist yet, and only those.
     *
     * @return int how many rows were created
     */
    public function generate(Product $product): int
    {
        $axes = $product->options()->with('values')->get()
            ->map(fn (ProductOption $option) => $option->values->pluck('id')->all())
            ->filter(fn (array $ids) => $ids !== [])
            ->values()
            ->all();

        if ($axes === []) {
            return 0;
        }

        $existing = ProductVariant::query()
            ->where('product_id', $product->id)
            ->with('optionValues')
            ->get()
            ->map(fn (ProductVariant $variant) => $this->key(
                $variant->optionValues->pluck('id')->map(fn ($id) => (int) $id)->all()
            ))
            ->all();

        $created = 0;
        $position = (int) ProductVariant::query()->where('product_id', $product->id)->max('position');

        foreach ($this->cartesian($axes) as $combination) {
            if (in_array($this->key($combination), $existing, true)) {
                continue;
            }

            DB::transaction(function () use ($product, $combination, &$position) {
                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'position' => ++$position,
                ]);

                $variant->optionValues()->attach($combination);

                // A generated variant starts out inheriting the product's
                // price, and that inherited price is what it is sold at — so
                // it needs its own row in the series from the first minute.
                $variant->setRelation('product', $product);
                $this->history->recordVariant($variant);
            });

            $created++;
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateVariant(ProductVariant $variant, array $attributes): ProductVariant
    {
        // Explicit whitelist into a $guarded=[] model — never $request->all().
        $variant->update(array_intersect_key($attributes, array_flip([
            'sku', 'ean', 'price', 'sale_price', 'stock_tracked', 'stock_qty', 'stock_policy', 'active',
        ])));

        $this->history->recordVariant($variant);

        return $variant;
    }

    /**
     * The variant for one exact combination of axis values, created if it is
     * not there yet.
     *
     * Axes and values the product does not have are created: unlike
     * categories, which are a shared tree where a typo pollutes the whole
     * shop, an axis belongs to a single product and a mistake is visible on
     * that product's own detail screen.
     *
     * @param  array<string, string>  $axes  axis name => value, e.g. ['Velikost' => 'M']
     * @param  array<string, mixed>  $attributes
     */
    public function upsertVariant(Product $product, array $axes, array $attributes): ProductVariant
    {
        return DB::transaction(function () use ($product, $axes, $attributes) {
            $valueIds = [];

            foreach ($axes as $axisName => $value) {
                $option = ProductOption::query()
                    ->where('product_id', $product->id)
                    ->where('name', $axisName)
                    ->first() ?? $this->addOption($product, $axisName);

                $optionValue = ProductOptionValue::query()
                    ->where('option_id', $option->id)
                    ->where('value', $value)
                    ->first() ?? $this->addValue($option, $value);

                $valueIds[] = (int) $optionValue->id;
            }

            sort($valueIds);

            $existing = ProductVariant::query()
                ->where('product_id', $product->id)
                ->with('optionValues')
                ->get()
                ->first(function (ProductVariant $variant) use ($valueIds) {
                    $ids = $variant->optionValues->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

                    return $ids === $valueIds;
                });

            if ($existing !== null) {
                return $this->updateVariant($existing, $attributes);
            }

            $position = (int) ProductVariant::query()->where('product_id', $product->id)->max('position');

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'position' => $position + 1,
                ...array_intersect_key($attributes, array_flip([
                    'sku', 'ean', 'price', 'sale_price', 'stock_tracked', 'stock_qty', 'stock_policy', 'active',
                ])),
            ]);

            $variant->optionValues()->attach($valueIds);
            $variant->setRelation('product', $product);
            $this->history->recordVariant($variant);

            return $variant;
        });
    }

    public function deleteVariant(ProductVariant $variant): void
    {
        $variant->delete();
    }

    /**
     * @param  Collection<int, ProductOption|ProductOptionValue>  $ordered
     */
    private function swapPosition($ordered, $model, int $direction): void
    {
        $index = $ordered->search(fn ($item) => $item->id === $model->id);

        if ($index === false) {
            return;
        }

        $target = $ordered->get($index + ($direction < 0 ? -1 : 1));

        if ($target === null) {
            return;
        }

        // Positions are swapped, not renumbered: a gap-free sequence is not
        // required and renumbering would rewrite every sibling row.
        $mine = $model->position;
        $model->update(['position' => $target->position]);
        $target->update(['position' => $mine]);
    }

    /**
     * @param  array<int, array<int, int>>  $axes
     * @return array<int, array<int, int>>
     */
    private function cartesian(array $axes): array
    {
        $result = [[]];

        foreach ($axes as $values) {
            $next = [];

            foreach ($result as $partial) {
                foreach ($values as $value) {
                    $next[] = [...$partial, $value];
                }
            }

            $result = $next;
        }

        return $result;
    }

    /**
     * Order-independent identity of a combination.
     *
     * @param  array<int, int>  $optionValueIds
     */
    private function key(array $optionValueIds): string
    {
        sort($optionValueIds);

        return implode('-', $optionValueIds);
    }
}
