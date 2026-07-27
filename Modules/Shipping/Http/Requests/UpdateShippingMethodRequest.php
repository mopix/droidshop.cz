<?php

namespace Modules\Shipping\Http\Requests;

/**
 * Editing a shipping method validates almost exactly as creating one does —
 * the same fields, the same address requirement for pickup — except the
 * Packeta password is optional. A blank api_password on update means "keep
 * the stored one": the admin never sees the full password, so re-typing it
 * just to save an unrelated change would be impossible. The writer only
 * overwrites the credential when a new value actually arrives.
 */
class UpdateShippingMethodRequest extends StoreShippingMethodRequest
{
    // api_password is excluded from session flash app-wide (bootstrap/app.php);
    // see StoreShippingMethodRequest.

    /**
     * @return array<int, mixed>
     */
    protected function apiPasswordRule(): array
    {
        return ['nullable', 'string', 'max:255'];
    }
}
