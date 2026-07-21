<?php

namespace Modules\Shipping\Http\Requests;

/**
 * Editing a payment method differs from creating one in one place: the account
 * is optional. A blank account on update means "keep the stored one" — the
 * admin never sees the full account, so re-typing it just to save an unrelated
 * change would be impossible. The writer only overwrites the secret when a new
 * value actually arrives.
 */
class UpdatePaymentMethodRequest extends StorePaymentMethodRequest
{
    /**
     * @return array<int, mixed>
     */
    protected function accountRule(): array
    {
        return [
            'nullable',
            'string',
            'max:64',
            $this->accountFormat(),
        ];
    }

    /**
     * The Comgate secret is optional on update: blank means "keep the stored
     * one", the same rule as the account. merchant (not secret) stays required.
     *
     * @return array<int, mixed>
     */
    protected function secretRule(): array
    {
        return ['nullable', 'string', 'max:128'];
    }
}
