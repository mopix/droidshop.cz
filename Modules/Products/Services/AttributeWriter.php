<?php

namespace Modules\Products\Services;

use Illuminate\Support\Str;
use Modules\Products\Exceptions\AttributeInUse;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductAttribute;
use Modules\Products\Models\ProductAttributeValue;

/**
 * The one way attributes and their values are written.
 *
 * Slugs and codes are generated here rather than at each call site, because
 * they are what filter URLs are built from: a slug invented twice is two links
 * to the same shelf, and a slug that changes when a label is fixed is a link
 * that stops working.
 */
class AttributeWriter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ProductAttribute
    {
        return ProductAttribute::create([
            'code' => $this->uniqueCode($data['code'] ?? $data['name']),
            'name' => $data['name'],
            'position' => $data['position'] ?? (int) ProductAttribute::query()->max('position') + 1,
            'is_filterable' => $data['is_filterable'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductAttribute $attribute, array $data): ProductAttribute
    {
        // The code is not editable through here on purpose: it is the stable
        // half of the pair, and renaming it would silently break every link a
        // customer has shared or a crawler has indexed.
        $attribute->update([
            'name' => $data['name'] ?? $attribute->name,
            'position' => $data['position'] ?? $attribute->position,
            'is_filterable' => $data['is_filterable'] ?? $attribute->is_filterable,
        ]);

        return $attribute->fresh();
    }

    public function delete(ProductAttribute $attribute): void
    {
        $used = ProductAttributeValue::query()
            ->where('attribute_id', $attribute->id)
            ->whereHas('products')
            ->exists();

        if ($used) {
            // Cascading would silently strip a property from goods that carry
            // it, and the merchant would find out from a customer asking why
            // the shop stopped saying what colour a thing is.
            throw AttributeInUse::forAttribute($attribute->name);
        }

        $attribute->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addValue(ProductAttribute $attribute, array $data): ProductAttributeValue
    {
        return ProductAttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => $data['value'],
            'slug' => $this->uniqueSlug($attribute, $data['value']),
            'position' => $data['position'] ?? (int) ProductAttributeValue::query()
                ->where('attribute_id', $attribute->id)->max('position') + 1,
        ]);
    }

    public function renameValue(ProductAttributeValue $value, string $label): ProductAttributeValue
    {
        // The label changes, the slug does not — see the class docblock.
        $value->update(['value' => $label]);

        return $value->fresh();
    }

    /**
     * The values a product carries, replacing whatever it had.
     *
     * @param  list<int>  $valueIds
     */
    public function syncForProduct(Product $product, array $valueIds): void
    {
        // Filtered through the tenant's own value list rather than trusted:
        // the ids arrive from a form, and an id from another shop would
        // otherwise become a row in this shop's pivot.
        $ids = ProductAttributeValue::query()
            ->whereIn('id', $valueIds)
            ->pluck('id')
            ->all();

        // tenant_id is stamped explicitly, the same way syncCategories does
        // it: a pivot row is not an Eloquent model, so BelongsToTenant never
        // sees it and the column would be left empty.
        $product->attributeValues()->sync(
            collect($ids)
                ->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => $product->tenant_id]])
                ->all()
        );
    }

    private function uniqueCode(string $source): string
    {
        $base = Str::limit(Str::slug($source), 60, '') ?: 'vlastnost';
        $code = $base;
        $suffix = 2;

        while (ProductAttribute::query()->where('code', $code)->exists()) {
            $code = $base.'-'.$suffix++;
        }

        return $code;
    }

    private function uniqueSlug(ProductAttribute $attribute, string $source): string
    {
        $base = Str::limit(Str::slug($source), 60, '') ?: 'hodnota';
        $slug = $base;
        $suffix = 2;

        while (ProductAttributeValue::query()
            ->where('attribute_id', $attribute->id)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
