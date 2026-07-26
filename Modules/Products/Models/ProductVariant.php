<?php

namespace Modules\Products\Models;

use App\Core\Catalog\Contracts\CatalogVariant;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One buyable combination of option values.
 *
 * Price and stock live here when a product has variants; the product's own
 * columns are then a fallback (price) and ignored (stock). Keeping both
 * would mean two answers to "how many are left", and the wrong one would be
 * the one someone reads.
 */
class ProductVariant extends Model implements CatalogVariant
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $attributes = [
        'stock_tracked' => false,
        'stock_qty' => 0,
        'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
        'active' => true,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'stock_tracked' => 'boolean',
            'stock_qty' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductOptionValue::class,
            'product_variant_values',
            'variant_id',
            'option_value_id',
        )->using(ProductVariantValue::class)->withPivot('tenant_id');
    }

    /**
     * "Velikost: M, Barva: červená" — in option order, so two variants of the
     * same product always read the same way round.
     */
    public function label(): string
    {
        return $this->optionValues
            ->load('option')
            ->sortBy(fn (ProductOptionValue $value) => $value->option->position)
            ->map(fn (ProductOptionValue $value) => $value->option->name.': '.$value->value)
            ->implode(', ');
    }

    /**
     * The variant's own price, or the product's when it has none.
     *
     * loadMissing(), not a bare $this->product: a caller iterating a
     * collection of variants for the same product (Product::catalogPriceFrom(),
     * a category listing rendering many cards) can set the inverse relation
     * once up front, and this then costs nothing per variant instead of one
     * query per null-priced variant.
     */
    public function effectivePrice(): Money
    {
        if ($this->price !== null) {
            return $this->price;
        }

        $this->loadMissing('product');

        return $this->product->price;
    }

    public function isAvailable(int $quantity = 1): bool
    {
        if (! $this->active) {
            return false;
        }

        if (! $this->stock_tracked || $this->stock_policy === Product::STOCK_POLICY_BACKORDER) {
            return true;
        }

        return $this->stock_qty >= $quantity;
    }

    public function catalogVariantSku(): ?string
    {
        return $this->sku;
    }

    public function catalogVariantLabel(): string
    {
        return $this->label();
    }

    public function catalogVariantPrice(): Money
    {
        return $this->effectivePrice();
    }

    public function catalogVariantIsAvailable(int $quantity = 1): bool
    {
        return $this->isAvailable($quantity);
    }

    /**
     * @return array<int, int>
     */
    public function catalogVariantSelection(): array
    {
        return $this->optionValues
            ->mapWithKeys(fn (ProductOptionValue $value) => [(int) $value->option_id => (int) $value->id])
            ->all();
    }
}
