<?php

namespace Modules\Discounts\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Discounts\Models\Discount;

/**
 * Validates a new coupon (has a code) or automatic rule (does not).
 *
 * `value` is stored exactly as posted: permille for a percent discount,
 * haléře for a fixed one, 0 for free shipping — the same "raw stored unit,
 * no server-side unit conversion" stance StoreProductRequest already takes
 * for money. The tenant-facing "%" label and the percent↔permille math live
 * entirely in Form.vue: it multiplies what the admin types by 10 before the
 * request is ever sent, so this class never has to know the difference
 * between a freshly typed percent and a value already in permille.
 */
class StoreDiscountRequest extends FormRequest
{
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
            // Tenant-scoped uniqueness: two shops may each run a VITEJTE code.
            'code' => [
                'nullable', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('discounts', 'code')->where('tenant_id', $tenantId),
            ],
            'type' => ['required', Rule::in([
                Discount::TYPE_PERCENT, Discount::TYPE_FIXED, Discount::TYPE_FREE_SHIPPING,
            ])],
            'value' => ['required_unless:type,'.Discount::TYPE_FREE_SHIPPING, 'integer', 'min:0', 'max:1000000'],
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
        $code = $this->input('code');

        if ($code === null) {
            return;
        }

        $normalised = mb_strtoupper(trim((string) $code));

        // An empty/whitespace-only code becomes null (an automatic rule), not
        // a string that then fails the regex — the admin left the field
        // blank on purpose.
        $this->merge(['code' => $normalised === '' ? null : $normalised]);
    }

    /**
     * Which table a `targets` entry must exist in depends on `scope`, and the
     * lookup is always scoped to this tenant — a posted id is never trusted,
     * the same stance StoreProductRequest takes for category_ids.
     *
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
            // scope=cart (or anything invalid): the target picker is not
            // reachable in the UI, so no id posted here is ever legitimate.
            return ['prohibited'];
        }

        return ['integer', Rule::exists($table, 'id')->where('tenant_id', $tenantId)];
    }
}
