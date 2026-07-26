<?php

namespace Modules\Products\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Products\Http\Requests\StoreOptionValueRequest;
use Modules\Products\Http\Requests\StoreProductOptionRequest;
use Modules\Products\Http\Requests\UpdateProductVariantRequest;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductOptionValue;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\VariantWriter;

/**
 * The variant matrix editor's write endpoints.
 *
 * Every method resolves its child row through the product it hangs off, so
 * an id belonging to another product (or another tenant, which the global
 * scope already hides) is a 404 rather than a silent cross-product edit.
 */
class ProductVariantAdminController
{
    public function __construct(private readonly VariantWriter $writer) {}

    public function storeOption(StoreProductOptionRequest $request, Product $product): RedirectResponse
    {
        $this->writer->addOption($product, $request->validated('name'));

        return back()->with('success', 'Vlastnost přidána.');
    }

    public function updateOption(StoreProductOptionRequest $request, Product $product, int $option): RedirectResponse
    {
        $this->writer->renameOption($this->option($product, $option), $request->validated('name'));

        return back()->with('success', 'Vlastnost přejmenována.');
    }

    public function destroyOption(Request $request, Product $product, int $option): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);

        $this->writer->deleteOption($this->option($product, $option));

        return back()->with('success', 'Vlastnost odebrána.');
    }

    public function moveOption(Request $request, Product $product, int $option): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);

        $this->writer->moveOption($this->option($product, $option), $this->direction($request));

        return back();
    }

    public function storeValue(StoreOptionValueRequest $request, Product $product, int $option): RedirectResponse
    {
        $this->writer->addValue($this->option($product, $option), $request->validated('value'));

        return back()->with('success', 'Hodnota přidána.');
    }

    public function destroyValue(Request $request, Product $product, int $option, int $value): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);

        $this->writer->deleteValue($this->value($product, $option, $value));

        return back()->with('success', 'Hodnota odebrána.');
    }

    public function moveValue(Request $request, Product $product, int $option, int $value): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);

        $this->writer->moveValue($this->value($product, $option, $value), $this->direction($request));

        return back();
    }

    public function generate(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);

        $created = $this->writer->generate($product);

        return back()->with('success', $created === 0
            ? 'Všechny kombinace už existují.'
            : "Vytvořeno kombinací: {$created}.");
    }

    public function update(UpdateProductVariantRequest $request, Product $product, int $variant): RedirectResponse
    {
        $this->writer->updateVariant($this->variant($product, $variant), $request->validated());

        return back()->with('success', 'Varianta uložena.');
    }

    public function destroy(Request $request, Product $product, int $variant): RedirectResponse
    {
        abort_unless($request->user()->can('products.edit'), 403);

        $this->writer->deleteVariant($this->variant($product, $variant));

        return back()->with('success', 'Varianta smazána.');
    }

    private function option(Product $product, int $option): ProductOption
    {
        return ProductOption::query()->where('product_id', $product->id)->whereKey($option)->firstOrFail();
    }

    private function value(Product $product, int $option, int $value): ProductOptionValue
    {
        return ProductOptionValue::query()
            ->where('option_id', $this->option($product, $option)->id)
            ->whereKey($value)
            ->firstOrFail();
    }

    private function variant(Product $product, int $variant): ProductVariant
    {
        return ProductVariant::query()->where('product_id', $product->id)->whereKey($variant)->firstOrFail();
    }

    private function direction(Request $request): int
    {
        return $request->input('direction') === 'up' ? -1 : 1;
    }
}
