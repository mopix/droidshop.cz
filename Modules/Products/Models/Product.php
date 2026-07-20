<?php

namespace Modules\Products\Models;

use App\Core\Catalog\Contracts\CatalogProduct;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Categories\Models\Category;

class Product extends Model implements CatalogProduct
{
    use BelongsToTenant;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_HIDDEN = 'hidden';

    /** Out of stock: hide the product entirely. */
    public const STOCK_POLICY_HIDE = 'hide';

    /** Out of stock: keep it listed, marked as sold out. */
    public const STOCK_POLICY_SOLD_OUT = 'show_sold_out';

    /** Out of stock: sell anyway, ship later. */
    public const STOCK_POLICY_BACKORDER = 'backorder';

    protected $guarded = [];

    /**
     * Column defaults repeated here so a freshly created instance answers the
     * same as one read back from the database — a product is a draft from the
     * moment it exists, not from the first reload.
     */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'stock_policy' => self::STOCK_POLICY_SOLD_OUT,
        'stock_tracked' => false,
        'stock_qty' => 0,
        'weight_g' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'compare_at_price' => MoneyCast::class,
            'purchase_price' => MoneyCast::class,
            'stock_tracked' => 'boolean',
            'stock_qty' => 'integer',
            'weight_g' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_category')
            ->withPivot('is_primary');
    }

    public function primaryCategory(): ?Category
    {
        return $this->categories->firstWhere('pivot.is_primary', true)
            ?? $this->categories->first();
    }

    /**
     * Products a customer may see.
     *
     * Hidden means "reachable by direct link but not listed"; draft means not
     * public at all. The distinction matters for the storefront, so it lives
     * here rather than in each caller.
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function rate(): TaxRate
    {
        return app(TaxRates::class)->findById($this->tax_rate_id);
    }

    public function netPrice(): Money
    {
        return $this->rate()->net($this->price);
    }

    public function vat(): Money
    {
        return $this->rate()->vat($this->price);
    }

    public function url(): string
    {
        // Flat product URLs (decision 2026-07-19): reorganising the catalogue
        // must not change the address of every product in a subtree.
        return '/produkt/'.$this->slug;
    }

    public function isAvailable(int $quantity = 1): bool
    {
        if (! $this->stock_tracked || $this->stock_policy === self::STOCK_POLICY_BACKORDER) {
            return true;
        }

        return $this->stock_qty >= $quantity;
    }

    public function catalogName(): string
    {
        return $this->name;
    }

    public function catalogSlug(): string
    {
        return $this->slug;
    }

    public function catalogSku(): ?string
    {
        return $this->sku;
    }

    public function catalogPrice(): Money
    {
        return $this->price;
    }

    public function catalogNetPrice(): Money
    {
        return $this->netPrice();
    }

    public function catalogVat(): Money
    {
        return $this->vat();
    }

    public function catalogWeightGrams(): int
    {
        return $this->weight_g;
    }

    public function catalogIsAvailable(int $quantity = 1): bool
    {
        return $this->isAvailable($quantity);
    }
}
