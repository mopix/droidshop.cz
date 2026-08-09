<?php

namespace Modules\Discounts\Http\Requests;

use App\Core\Money\ConvertsMoneyInput;
use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Discounts\Models\Discount;

/**
 * Same rules as StoreDiscountRequest, minus the create-only bits: `code`
 * uniqueness ignores this row's own id, and everything else is identical
 * (see StoreDiscountRequest's docblock for the `value` unit convention).
 */
class UpdateDiscountRequest extends FormRequest
{
    use ConvertsMoneyInput;

    public function authorize(): bool
    {
        return (bool) $this->user('web')?->can('discounts.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'nullable', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('discounts', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($this->route('discount')),
            ],
            'type' => ['required', Rule::in([
                Discount::TYPE_PERCENT, Discount::TYPE_FIXED, Discount::TYPE_FREE_SHIPPING,
            ])],
            // See StoreDiscountRequest::rules() for why the ceiling depends
            // on the type: 1000 permille (=100%) for a percent discount,
            // otherwise the generic haléře ceiling.
            'value' => [
                'required_unless:type,'.Discount::TYPE_FREE_SHIPPING, 'integer', 'min:0',
                'max:'.($this->input('type') === Discount::TYPE_PERCENT ? 1000 : 1000000),
            ],
            'scope' => ['required', Rule::in([
                Discount::SCOPE_CART, Discount::SCOPE_CATEGORIES, Discount::SCOPE_PRODUCTS,
            ])],
            'targets' => ['array'],
            'targets.*' => $this->targetRule($tenantId),
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'min_cart_total' => ['nullable', 'integer', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_email' => ['nullable', 'integer', 'min:1'],
            'requires_login' => ['boolean'],
            'first_order_only' => ['boolean'],
            'combinable' => ['boolean'],
            'active' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kód smí obsahovat jen velká písmena bez diakritiky, číslice, pomlčku a podtržítko.',
            'code.unique' => 'Tento kód už v e-shopu existuje.',
            'ends_at.after' => 'Konec platnosti musí být po jejím začátku.',
            'targets.*.prohibited' => 'Cíle lze vybrat jen u slevy na kategorie nebo produkty.',
            'targets.*.exists' => 'Vybraný cíl neexistuje v tomto e-shopu.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Korunas in, haléře out (wave 3.8) — but only for the fields that
        // ARE money. `value` is money only for a fixed-amount discount; for a
        // percentage it is per mille (10 % is 100), and running that through a
        // koruna parser would turn a tenth off into ten times the basket.
        $moneyFields = ['min_cart_total'];

        if ($this->input('type') === Discount::TYPE_FIXED) {
            $moneyFields[] = 'value';
        }

        $this->convertMoneyFields($moneyFields);

        $code = $this->input('code');

        if ($code !== null) {
            $normalised = mb_strtoupper(trim((string) $code));
            $code = $normalised === '' ? null : $normalised;

            $this->merge(['code' => $code]);
        }

        // See StoreDiscountRequest::prepareForValidation(): a coupon's own
        // `combinable` flag is never read by the evaluator, so a direct POST
        // must not be able to leave a stale `false` on it.
        if ($code !== null) {
            $this->merge(['combinable' => true]);
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function targetRule(?int $tenantId): array
    {
        $table = match ($this->input('scope')) {
            Discount::SCOPE_CATEGORIES => 'categories',
            Discount::SCOPE_PRODUCTS => 'products',
            default => null,
        };

        if ($table === null) {
            return ['prohibited'];
        }

        return ['integer', Rule::exists($table, 'id')->where('tenant_id', $tenantId)];
    }
}
