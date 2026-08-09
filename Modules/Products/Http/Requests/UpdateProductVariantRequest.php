<?php

namespace Modules\Products\Http\Requests;

use App\Core\Money\ConvertsMoneyInput;
use App\Core\Money\Money;
use App\Core\Tax\VatMode;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Products\Models\Product;

class UpdateProductVariantRequest extends FormRequest
{
    use ConvertsMoneyInput;

    public function authorize(): bool
    {
        return $this->user()?->can('products.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // null is meaningful: inherit the product's price.
            'price' => ['nullable', 'integer', 'min:0'],

            // Entering a variant's price without VAT (wave 3.8), same rule as
            // the product's own price field: a helper for typing, never a
            // stored column, and converted here rather than in the browser.
            'net_price' => ['nullable', 'integer', 'min:0'],

            // null = no sale amount of its own. A variant that inherits the
            // base price inherits the product's campaign amount too; one with
            // its own price has to name its own, or it sells at nominal.
            'sale_price' => ['nullable', 'integer', 'min:0'],
            'sku' => ['nullable', 'string', 'max:64'],
            'ean' => ['nullable', 'string', 'max:14'],
            'stock_tracked' => ['boolean'],
            'stock_qty' => ['integer'],
            'stock_policy' => ['in:'.implode(',', [
                Product::STOCK_POLICY_HIDE,
                Product::STOCK_POLICY_SOLD_OUT,
                Product::STOCK_POLICY_BACKORDER,
            ])],
            'active' => ['boolean'],
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        unset($data['net_price']);

        return $key === null ? $data : data_get($data, $key, $default);
    }

    /**
     * Turns a net price into the gross one that gets stored.
     *
     * The rate is the PRODUCT's — a variant never carries one of its own
     * (wave 2.4) — so it is read off the bound product rather than the
     * request, where a client could name a different one.
     *
     * The gross field wins when both arrive, for the same reason it does on
     * the product: recomputing from net on every save would walk the price by
     * a haléř each time somebody pressed Save without changing anything.
     */
    protected function prepareForValidation(): void
    {
        $this->convertMoneyFields(['price', 'net_price', 'sale_price']);

        if (! app(VatMode::class)->appliesVat()) {
            return;
        }

        if (! $this->filled('net_price') || $this->filled('price')) {
            return;
        }

        $product = $this->route('product');

        if (! $product instanceof Product) {
            return;
        }

        $this->merge([
            'price' => $product->rate()->gross(
                new Money((int) $this->input('net_price'), config('app.currency', 'CZK'))
            )->amount,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.integer' => 'Cena musí být číslo v haléřích.',
            'price.min' => 'Cena nesmí být záporná.',
        ];
    }
}
