<?php

namespace Modules\Products\Models;

use App\Core\Catalog\Contracts\CatalogProduct;
use App\Core\Money\Money;
use App\Core\Money\MoneyCast;
use App\Core\Storage\FileStorage;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Theme\VariantDisplay;
use App\Models\TaxRate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Categories\Models\Category;
use Modules\Products\Support\SearchText;

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
            'sale_price' => MoneyCast::class,
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
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

    /**
     * Keeps the searchable form in step with the product.
     *
     * On write rather than on read: search has to compare against something
     * already folded, and folding a whole table per query would make the
     * index useless.
     */
    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            $product->search_text = SearchText::normalise(
                $product->name,
                $product->sku,
                $product->ean,
                $product->short_description,
            );
        });
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

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
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
     * The image the storefront leads with: the one flagged main, else the
     * first by position. Never null-checked at the call site by accident —
     * a product without images is ordinary, not an error.
     */
    public function mainImage(): ?ProductImage
    {
        return $this->images->firstWhere('is_main', true) ?? $this->images->first();
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

    /**
     * Whether the campaign window is open right now.
     *
     * Deliberately independent of sale_price: a shop may run a campaign in
     * which only one variant is discounted, and that variant's amount still
     * has to respect the product's dates.
     */
    public function saleWindowIsOpen(?CarbonInterface $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        if ($this->sale_starts_at !== null && $this->sale_starts_at->greaterThan($at)) {
            return false;
        }

        return $this->sale_ends_at === null || $this->sale_ends_at->greaterThan($at);
    }

    public function saleIsRunning(?CarbonInterface $at = null): bool
    {
        return $this->sale_price !== null && $this->saleWindowIsOpen($at);
    }

    /**
     * What a customer actually pays for this product right now.
     *
     * Every price the rest of the platform reads goes through here, which is
     * why the cart, orders and documents need no change to charge a sale
     * price — and why the discount engine (wave 2.6) computes a coupon from
     * the discounted price rather than the shelf price.
     */
    public function effectivePrice(): Money
    {
        return $this->saleIsRunning() ? $this->sale_price : $this->price;
    }

    /**
     * The same decision as effectivePrice(), expressed in SQL so a listing can
     * order by what a shopper actually pays. Takes two bindings, both "now".
     *
     * Plain CASE WHEN rather than a database function: it has to run on MySQL
     * in production and on SQLite in tests, identically.
     */
    public static function effectivePriceExpression(): string
    {
        return '(case when sale_price is not null'
            .' and (sale_starts_at is null or sale_starts_at <= ?)'
            .' and (sale_ends_at is null or sale_ends_at > ?)'
            .' then sale_price else price end)';
    }

    public function netPrice(): Money
    {
        return $this->rate()->net($this->effectivePrice());
    }

    public function vat(): Money
    {
        return $this->rate()->vat($this->effectivePrice());
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
        return $this->effectivePrice();
    }

    /**
     * The nominal price — what gets struck through next to a sale price.
     */
    public function catalogRegularPrice(): Money
    {
        return $this->price;
    }

    public function catalogIsOnSale(): bool
    {
        return $this->saleIsRunning();
    }

    public function catalogLowestPriceIn30Days(): ?Money
    {
        // The history the answer comes from does not exist yet — the recorder
        // and the calculator land later in this wave. Until then the honest
        // answer is "no reference known", which the storefront renders as no
        // line at all rather than as a made-up figure.
        return null;
    }

    public function catalogNetPrice(): Money
    {
        return $this->netPrice();
    }

    public function catalogVat(): Money
    {
        return $this->vat();
    }

    public function catalogTaxRatePercent(): float
    {
        $rates = app(TaxRates::class);

        // tax_rate_id is a required column today (see the products
        // migration), but a caller of the contract must not have to know
        // that — fall back to the shop's default rate rather than crash.
        $rate = $this->tax_rate_id !== null
            ? $rates->findById($this->tax_rate_id)
            : $rates->default();

        return $rate->percent();
    }

    public function catalogWeightGrams(): int
    {
        return $this->weight_g;
    }

    /**
     * For a product with variants, "available" means at least one variant
     * is — the product's own stock columns are not the authority once
     * variants exist (spec: variants own stock, the product's is a fallback
     * only for a variant-less product). Mirrors show.blade.php's inline
     * computation, which is why that view now calls this instead of
     * repeating it (review fix: the two must never disagree).
     */
    public function catalogIsAvailable(int $quantity = 1): bool
    {
        if (! $this->catalogHasVariants()) {
            return $this->isAvailable($quantity);
        }

        return $this->variants->contains(fn (ProductVariant $variant) => $variant->isAvailable($quantity));
    }

    public function catalogShortDescription(): ?string
    {
        return $this->short_description;
    }

    public function catalogImageUrl(): ?string
    {
        $image = $this->mainImage();

        return $image === null ? null : app(FileStorage::class)->publicUrl($image->path);
    }

    public function catalogImageAlt(): ?string
    {
        return $this->mainImage()?->alt;
    }

    public function catalogUrl(): string
    {
        return $this->url();
    }

    /**
     * Whether this product carries variants at all — ANY row, active or
     * not (review fix: filtering to active here let a product whose last
     * variant went inactive silently revert to being sold at the base
     * price with no stock accounting; see OrderPlacer::recomputeLines()
     * and CartPricer::price(), which refuse a variant-less line on a
     * product that answers true here).
     *
     * Reads $this->variants (the relation, not variants()) so a caller that
     * eager-loaded it (EloquentProductCatalog::paginate()/latest()/search())
     * pays no extra query per row, and a caller that did not still gets it
     * cached after the first access instead of re-queried by every other
     * catalog*() method below.
     */
    public function catalogHasVariants(): bool
    {
        return $this->variants->isNotEmpty();
    }

    /**
     * The "od" price: cheapest variant that is actually buyable right now,
     * falling back to the cheapest active-but-sold-out one when nothing is
     * available, falling back to the product's own price when there is no
     * active variant at all (the same case catalogHasVariants() still
     * reports true for — a shop that deactivated every variant shows
     * "Vyprodáno" via catalogIsAvailable(), not this product's own price
     * disguised as an "od" figure).
     */
    public function catalogPriceFrom(): Money
    {
        $variants = $this->variants;

        if ($variants->isEmpty()) {
            return $this->price;
        }

        $active = $variants->filter(fn (ProductVariant $variant) => $variant->active);

        if ($active->isEmpty()) {
            return $this->price;
        }

        $available = $active->filter(fn (ProductVariant $variant) => $variant->isAvailable());
        $pool = $available->isNotEmpty() ? $available : $active;

        // These variants are this product's own — set the inverse relation
        // directly rather than let effectivePrice() lazy-load the parent
        // once per null-priced variant (a listing renders this per card).
        foreach ($pool as $variant) {
            $variant->setRelation('product', $this);
        }

        return $pool
            ->map(fn (ProductVariant $variant) => $variant->effectivePrice())
            ->sort(fn (Money $a, Money $b) => $a->amount <=> $b->amount)
            ->first();
    }

    public function catalogVariantDisplay(): string
    {
        if ($this->variant_display !== null) {
            return VariantDisplay::sanitize($this->variant_display);
        }

        return app(VariantDisplay::class)->forCurrentTenant();
    }

    /**
     * @return list<int>
     */
    public function catalogCategoryIds(): array
    {
        // The relation PROPERTY, not the categories() query builder method:
        // reading $this->categories lazy-loads once and memoises on the
        // model (and is eager-loadable by a future caller via ->with()),
        // where categories()->pluck(...) fires a brand new query every
        // single call (review finding, wave 2.6 — see
        // EloquentProductCatalog::paginate()'s own docblock for the same
        // property-vs-method distinction on 'variants').
        //
        // map(static fn ...) rather than map(intval(...)): Collection::map
        // invokes the callback as ($item, $key), so a bare first-class
        // callable intval(...) receives the collection KEY as intval()'s
        // $base argument and silently zeroes every id but the first
        // (verified on PHP 8.3 — collect(["12","34"])->map(intval(...))
        // yields [12, 0]). Masked today only because PDO_MySQL returns
        // native ints for integer columns; a connection that stringifies
        // fetches would break scope=categories discounts silently.
        return $this->categories->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
