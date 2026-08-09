<?php

namespace Modules\Products\Http\Requests;

use App\Core\Limits\LimitsService;
use App\Core\Money\ConvertsMoneyInput;
use App\Core\Money\Money;
use App\Core\Tax\TaxRates;
use App\Core\Tax\VatMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Rules\Ean;

class StoreProductRequest extends FormRequest
{
    use ConvertsMoneyInput;

    public function authorize(): bool
    {
        return $this->user()->can('products.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'slug' => ['nullable', 'string', 'max:185', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'status' => ['required', Rule::in([
                Product::STATUS_DRAFT, Product::STATUS_ACTIVE, Product::STATUS_HIDDEN,
            ])],

            'short_description' => ['nullable', 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:65000'],

            // Prices arrive as haléře, never as a decimal string: a float on
            // its way to the database is how a price loses a haléř.
            'price' => ['required', 'integer', 'min:0'],

            // A "sale" above the shelf price is either a typo or a dark
            // pattern, and neither belongs in the catalogue. The window lives
            // on the product: one campaign, amounts per variant.
            'sale_price' => ['nullable', 'integer', 'min:0', 'lt:price'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after:sale_starts_at'],

            'purchase_price' => ['nullable', 'integer', 'min:0'],

            // A shop that is not registered for VAT has no rate to charge, so
            // it is not asked for one (same rule shipping and payment fees
            // already follow since wave 2.7). An existing rate on the row is
            // left where it is — see prepareForValidation().
            'tax_rate_id' => [
                Rule::requiredIf(fn () => app(VatMode::class)->appliesVat()),
                'nullable', 'integer', Rule::exists('tax_rates', 'id'),
            ],

            // Entering the price without VAT (wave 3.7). A convenience for
            // wholesale price lists, not a second source of truth: the gross
            // price is still the only figure stored, and the conversion runs
            // here on the server, never in the browser.
            'net_price' => ['nullable', 'integer', 'min:0'],

            'sku' => ['nullable', 'string', 'max:64'],
            'ean' => ['nullable', new Ean],
            'manufacturer' => ['nullable', 'string', 'max:191'],
            'weight_g' => ['required', 'integer', 'min:0', 'max:200000'],

            // Millimetres, nullable. The ceiling is two metres: anything
            // beyond that is a typo, not a parcel any of our carriers takes.
            'length_mm' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'width_mm' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'height_mm' => ['nullable', 'integer', 'min:1', 'max:2000'],

            'stock_tracked' => ['boolean'],
            'stock_qty' => ['integer'],
            'stock_policy' => ['required', Rule::in([
                Product::STOCK_POLICY_HIDE,
                Product::STOCK_POLICY_SOLD_OUT,
                Product::STOCK_POLICY_BACKORDER,
            ])],
            'stock_alert_qty' => ['nullable', 'integer', 'min:0'],

            'category_ids' => ['array'],
            'category_ids.*' => ['integer', Rule::exists(Category::class, 'id')],
            'primary_category_id' => ['nullable', 'integer', Rule::exists(Category::class, 'id')],

            'seo_title' => ['nullable', 'string', 'max:191'],
            'seo_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The plan limit is checked before the write, not after.
     *
     * Attached to `name` so the message lands on the form the admin is
     * looking at rather than in a bare exception page.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->enforcesProductLimit()) {
                    return;
                }

                $result = app(LimitsService::class)->check('products');

                if (! $result->allowed()) {
                    $validator->errors()->add('name', $result->message);
                }
            },
        ];
    }

    protected function enforcesProductLimit(): bool
    {
        return true;
    }

    /**
     * Costs are a separate right (spec §16.1).
     *
     * Dropped from the validated data, not merely hidden in the UI — the form
     * is not the boundary, this is. Stripping the raw input instead would not
     * help: controllers write from validated(), which reads the validator's
     * own copy.
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        if (! $this->user()->can('products.costs')) {
            unset($data['purchase_price']);
        }

        // A helper for filling the form in, never a column. The gross price is
        // the only figure stored (rozhodnutí 2026-07-21: the catalogue price
        // is the authority), and prepareForValidation() has already used this.
        unset($data['net_price']);

        return $key === null ? $data : data_get($data, $key, $default);
    }

    /**
     * Turns a price typed without VAT into the gross price that gets stored.
     *
     * On the server, never in the browser: a rate applied in JavaScript and a
     * rate applied in PHP round the same haléř differently often enough that
     * the merchant would watch the price they typed change on save. The
     * browser may preview the figure; it may not decide it (same rule the
     * variant picker follows since wave 2.4).
     *
     * When both fields arrive, the gross one wins. Recomputing from net on
     * every save would walk the price by a haléř each time somebody opened
     * the form and pressed Save without touching anything.
     */
    protected function prepareForValidation(): void
    {
        // Korunas in, haléře out — before any rule runs, so `lt:price` and the
        // VAT conversion below both compare like with like (wave 3.8).
        $this->convertMoneyFields(['price', 'net_price', 'sale_price', 'purchase_price']);

        $vat = app(VatMode::class);

        if (! $vat->appliesVat()) {
            // Nothing to convert, and nothing to ask for: a shop that is not
            // registered has no rate. A NEW product still needs one in the
            // column, so it gets the platform default — that way registering
            // for VAT later reads the stored prices as gross at the standard
            // rate, which is what the shelf price becomes in law. An existing
            // product keeps whatever it had (the key is simply absent).
            if ($this->route('product') === null && ! $this->filled('tax_rate_id')) {
                $this->merge(['tax_rate_id' => app(TaxRates::class)->default()->id]);
            }

            return;
        }

        if (! $this->filled('net_price') || $this->filled('price') || ! $this->filled('tax_rate_id')) {
            return;
        }

        $rate = app(TaxRates::class)->all()->firstWhere('id', (int) $this->input('tax_rate_id'));

        if ($rate === null) {
            return; // The rule below will refuse it; guessing a rate here would not help.
        }

        $this->merge([
            'price' => $rate->gross(new Money((int) $this->input('net_price'), config('app.currency', 'CZK')))->amount,
        ]);
    }
}
