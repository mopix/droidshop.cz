<?php

namespace Modules\Products\Http\Controllers;

use App\Core\Money\MoneyInput;
use App\Core\Tax\TaxRates;
use App\Core\Tax\VatMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Modules\Categories\Models\Category;
use Modules\Products\Http\Requests\StoreProductRequest;
use Modules\Products\Http\Requests\UpdateProductRequest;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductAddonGroup;
use Modules\Products\Models\ProductAttribute;
use Modules\Products\Rules\Ean;
use Modules\Products\Services\AttributeWriter;
use Modules\Products\Services\ProductImageService;
use Modules\Products\Services\ProductWriter;

class ProductAdminController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ProductWriter $writer,
        private readonly ProductImageService $images,
        private readonly TaxRates $rates,
        private readonly VatMode $vat,
        private readonly AttributeWriter $attributes,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('products.view'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:draft,active,hidden'],
            'category' => ['nullable', 'integer'],
        ]);

        $canSeeCosts = $request->user()->can('products.costs');
        $vatApplies = $this->vat->appliesVat();

        $products = Product::query()
            // Eager loaded: the listing shows a thumbnail and a category per
            // row, and lazy loading those is a query per row.
            ->with(['images', 'categories'])
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('sku', 'like', '%'.$search.'%')
            ))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->whereHas(
                'categories', fn ($c) => $c->whereKey($category)
            ))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'sku' => $product->sku,
                'ean' => $product->ean,
                // Whether the stored code is a real barcode. Not a validation
                // error any more (owner's decision, 2026-08-10) — the form
                // says so, and the feeds leave an invalid one out.
                'ean_valid' => $product->ean === null || Ean::isValid($product->ean),
                // Haléře here on purpose: the listing only displays the price
                // and formats it itself. Korunas are for the fields a person
                // types into, which is the detail form below.
                //
                // The shelf price, not the effective one: the sale has a
                // column of its own, and two columns showing the same figure
                // would hide what the discount was taken from (wave 3.10).
                'price' => $product->price->amount,
                // Never merely hidden in the UI: a value the caller may not
                // see does not leave the server (same rule as the detail form
                // and the CSV export).
                'purchase_price' => $canSeeCosts ? $product->purchase_price?->amount : null,
                // Net of the purchase price uses the SUPPLIER's rate (wave
                // 3.9), which may differ from the one the shop sells at.
                'purchase_net_price' => $canSeeCosts && $vatApplies && $product->purchase_price !== null
                    ? $product->purchaseRate()->net($product->purchase_price)->amount
                    : null,
                'sale_price' => $product->sale_price?->amount,
                // Net of the SHELF price, not of Product::netPrice(), which
                // is net of the effective one (wave 2.7). The gross column
                // beside it shows the shelf price, and two columns that
                // disagree about which price they describe are worse than one.
                'net_price' => $vatApplies ? $product->rate()->net($product->price)->amount : null,
                'tax_rate' => $vatApplies ? $product->rate()->percent() : null,
                'status' => $product->status,
                'stock_tracked' => $product->stock_tracked,
                'stock_qty' => $product->stock_qty,
                // The image the storefront leads with, not merely the first
                // one uploaded: the listing is where a merchant checks that
                // the right photo is the main one. Sent as a URL, because a
                // raw storage path is not something the browser can render.
                'image' => $product->mainImage() === null
                    ? null
                    : $this->images->url($product->mainImage()),
                'short_description' => $product->short_description,
                'categories' => $product->categories->map(fn ($category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                ])->values()->all(),
            ]);

        return inertia('Modules/Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => $this->categoryOptions(),
            // Which price columns the listing may draw (wave 3.10).
            'canSeeCosts' => $canSeeCosts,
            'vatApplies' => $vatApplies,
        ]);
    }

    public function show(Request $request, Product $product): Response
    {
        abort_unless($request->user()->can('products.view'), 403);

        $canSeeCosts = $request->user()->can('products.costs');
        $vatApplies = $this->vat->appliesVat();

        $product->load(['images', 'categories', 'manufacturer', 'attributeValues']);

        return inertia('Modules/Products/Show', [
            'product' => [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'status' => $product->status,
                'short_description' => $product->short_description,
                'description' => $product->description,
                // Korunas, formatted for a person to read and edit (wave 3.8).
                // The column stays in haléře; MoneyInput is the boundary.
                'price' => MoneyInput::toInput($product->price->amount),
                // Only a VAT payer is shown a net price; for anyone else the
                // figure is meaningless and the form has no field for it.
                'net_price' => $vatApplies ? MoneyInput::toInput($product->netPrice()->amount) : null,
                'sale_price' => MoneyInput::toInput($product->sale_price?->amount),
                'sale_starts_at' => $product->sale_starts_at?->format('Y-m-d\TH:i'),
                'sale_ends_at' => $product->sale_ends_at?->format('Y-m-d\TH:i'),
                // Not merely hidden in the UI: a value the caller may not see
                // never leaves the server.
                'purchase_price' => $canSeeCosts ? MoneyInput::toInput($product->purchase_price?->amount) : null,
                'purchase_net_price' => $canSeeCosts && $vatApplies && $product->purchase_price !== null
                    ? MoneyInput::toInput($product->purchaseRate()->net($product->purchase_price)->amount)
                    : null,
                'purchase_tax_rate_id' => $canSeeCosts ? $product->purchase_tax_rate_id : null,
                'sale_percent' => $product->sale_percent,
                'tax_rate_id' => $product->tax_rate_id,
                'sku' => $product->sku,
                'ean' => $product->ean,
                'manufacturer' => $product->manufacturer?->name,
                'weight_g' => $product->weight_g,
                'length_mm' => $product->length_mm,
                'width_mm' => $product->width_mm,
                'height_mm' => $product->height_mm,
                'stock_tracked' => $product->stock_tracked,
                'stock_qty' => $product->stock_qty,
                'stock_policy' => $product->stock_policy,
                'stock_alert_qty' => $product->stock_alert_qty,
                // null = inherit the shop-wide default (App\Core\Theme\VariantDisplay);
                // the editor's select needs to tell "inherit" apart from either
                // literal, so this stays null rather than a resolved default.
                'variant_display' => $product->variant_display,
                'seo_title' => $product->seo_title,
                'seo_description' => $product->seo_description,
                'url' => $product->url(),
                'images' => $product->images->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $this->images->url($image),
                    'alt' => $image->alt,
                    'is_main' => $image->is_main,
                ]),
                'category_ids' => $product->categories->pluck('id')->all(),
                'primary_category_id' => $product->primaryCategory()?->id,
            ],
            // Wave 3.7: a shop that is not registered for VAT gets no rate
            // list at all — not merely a hidden field. It cannot charge tax,
            // so it is never asked to pick a rate.
            'vatApplies' => $vatApplies,
            'taxRates' => ! $vatApplies ? [] : $this->rates->all()->values()->map(fn ($rate) => [
                'id' => $rate->id,
                'name' => $rate->name,
                'percent' => $rate->percent(),
            ]),
            'categories' => $this->categoryOptions(),
            // The whole code list plus what this product carries: the form
            // needs both to draw the checkboxes, and the server is the only
            // place that knows either.
            'attributes' => ProductAttribute::query()->with('values')->orderBy('position')->get()
                ->map(fn (ProductAttribute $attribute): array => [
                    'id' => $attribute->id,
                    'name' => $attribute->name,
                    'values' => $attribute->values->map(fn ($value): array => [
                        'id' => $value->id,
                        'value' => $value->value,
                    ]),
                ]),
            'attributeValueIds' => $product->attributeValues->pluck('id'),
            // Accessories offered with this product (wave 4.2). Read from the
            // module's own models rather than the kernel contract: this is the
            // admin, where the merchant edits the thing itself.
            'addonGroups' => ProductAddonGroup::query()
                ->where('product_id', $product->id)
                ->with('addons')
                ->orderBy('position')
                ->get()
                ->map(fn (ProductAddonGroup $group): array => [
                    'id' => $group->id,
                    'label' => $group->label,
                    'required' => $group->required,
                    'addons' => $group->addons->map(fn ($addon): array => [
                        'id' => $addon->id,
                        'label' => $addon->label,
                        'price' => MoneyInput::toInput($addon->price->amount),
                        'tax_rate_id' => $addon->tax_rate_id,
                    ]),
                ]),
            'options' => $product->options()->with('values')->get(),
            'variants' => $product->variants()->with('optionValues')->get()->map(fn ($variant) => [
                'id' => $variant->id,
                'label' => $variant->label(),
                'sku' => $variant->sku,
                'ean' => $variant->ean,
                // Korunas, like the product's own price fields (wave 3.8):
                // this grid is typed into, and null still means "inherit".
                'price' => MoneyInput::toInput($variant->price?->amount),
                'sale_price' => MoneyInput::toInput($variant->sale_price?->amount),
                'stock_tracked' => $variant->stock_tracked,
                'stock_qty' => $variant->stock_qty,
                'stock_policy' => $variant->stock_policy,
                'active' => $variant->active,
            ]),
            'can' => [
                'edit' => $request->user()->can('products.edit'),
                'costs' => $canSeeCosts,
            ],
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = $this->writer->create($this->attributes($request->validated()));

        $this->attributes->syncForProduct($product, $request->validated('attribute_value_ids', []));

        $this->writer->syncCategories(
            $product,
            $request->validated('category_ids', []),
            $request->validated('primary_category_id'),
        );

        return redirect()
            ->route('admin.products.show', $product->slug)
            ->with('success', 'Produkt byl vytvořen.');
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->writer->update($product, $this->attributes($request->validated()));

        $this->attributes->syncForProduct($product, $request->validated('attribute_value_ids', []));

        $this->writer->syncCategories(
            $product,
            $request->validated('category_ids', []),
            $request->validated('primary_category_id'),
        );

        return back()->with('success', 'Produkt byl uložen.');
    }

    /**
     * Changing a product's status straight from the listing (wave 3.12).
     *
     * Its own endpoint rather than the full update: the listing has none of
     * the other fields, and sending a half-filled product through
     * StoreProductRequest would blank whatever it did not carry.
     */
    public function updateStatus(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                Product::STATUS_DRAFT,
                Product::STATUS_ACTIVE,
                Product::STATUS_HIDDEN,
            ])],
        ]);

        // Through the writer, like every other write: it is what keeps the
        // search column and the price history in step.
        $this->writer->update($product, ['status' => $data['status']]);

        return back()->with('success', 'Stav produktu byl změněn.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);

        $this->writer->delete($product);

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Produkt byl smazán.');
    }

    /**
     * Turns validated input into product columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $manufacturer = $data['manufacturer'] ?? null;

        // Relations are synced separately and must not reach the writer as
        // columns: an UPDATE naming attribute_value_ids fails on "unknown
        // column", which is how this list was found to be missing one.
        unset(
            $data['manufacturer'],
            $data['category_ids'],
            $data['primary_category_id'],
            $data['attribute_value_ids'],
        );

        if ($manufacturer !== null && trim($manufacturer) !== '') {
            $data['manufacturer_id'] = $this->writer->manufacturer($manufacturer)->id;
        }

        return $data;
    }

    /**
     * @return list<array{id: int, name: string, depth: int}>
     */
    private function categoryOptions(): array
    {
        return Category::query()
            ->orderBy('path')
            ->orderBy('position')
            ->get(['id', 'name', 'depth'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'depth' => $category->depth,
            ])
            ->all();
    }
}
