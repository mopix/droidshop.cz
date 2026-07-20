<?php

namespace Modules\Products\Http\Requests;

use App\Core\Limits\LimitsService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Rules\Ean;

class StoreProductRequest extends FormRequest
{
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
            'compare_at_price' => ['nullable', 'integer', 'min:0'],
            'purchase_price' => ['nullable', 'integer', 'min:0'],
            'tax_rate_id' => ['required', 'integer', Rule::exists('tax_rates', 'id')],

            'sku' => ['nullable', 'string', 'max:64'],
            'ean' => ['nullable', new Ean],
            'manufacturer' => ['nullable', 'string', 'max:191'],
            'weight_g' => ['required', 'integer', 'min:0', 'max:200000'],

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

        return $key === null ? $data : data_get($data, $key, $default);
    }
}
