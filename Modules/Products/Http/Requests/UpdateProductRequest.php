<?php

namespace Modules\Products\Http\Requests;

class UpdateProductRequest extends StoreProductRequest
{
    /**
     * Editing an existing product does not add one, so the plan limit does not
     * apply. Enforcing it here would lock a shop that is already over its cap
     * out of fixing its own data.
     */
    protected function enforcesProductLimit(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // null = inherit the shop default; the two literals are the only
            // other accepted values.
            'variant_display' => ['nullable', 'in:radio,select'],
        ]);
    }
}
