<?php

namespace Modules\Checkout\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer details submitted on `/pokladna/udaje` before an order is
 * placed.
 *
 * Contact and address fields only — never a price. Any amount in the body is
 * simply not a declared field, so it is never read: the order's every figure
 * is recomputed from the catalogue by OrderPlacer (AK 5). The hidden
 * checkout_token is the idempotency key that makes a double submit collapse to
 * one order (AK 2); it is validated for shape only, its meaning is enforced by
 * the orders table's unique index.
 */
class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'checkout_token' => ['required', 'string', 'max:64'],

            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],

            'street' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'zip' => ['required', 'string', 'max:16'],
            'country' => ['required', 'string', 'size:2'],

            'company' => ['nullable', 'string', 'max:255'],
            // Czech IČO is exactly 8 digits; only checked when one is given.
            'ico' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'dic' => ['nullable', 'string', 'max:16'],

            // The optional delivery address. Its fields become required only
            // once the shopper ticks "doručit na jinou adresu".
            'ship_to_different' => ['nullable', 'boolean'],
            'delivery_name' => ['nullable', 'required_if:ship_to_different,1', 'string', 'max:255'],
            'delivery_street' => ['nullable', 'required_if:ship_to_different,1', 'string', 'max:255'],
            'delivery_city' => ['nullable', 'required_if:ship_to_different,1', 'string', 'max:255'],
            'delivery_zip' => ['nullable', 'required_if:ship_to_different,1', 'string', 'max:16'],
            'delivery_country' => ['nullable', 'required_if:ship_to_different,1', 'string', 'size:2'],

            'note' => ['nullable', 'string', 'max:1000'],

            // Consent is mandatory: the order cannot be placed without it.
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Zadejte e-mail.',
            'email.email' => 'Zadejte platný e-mail.',
            'phone.required' => 'Zadejte telefon.',
            'name.required' => 'Zadejte jméno a příjmení.',
            'street.required' => 'Zadejte ulici a číslo popisné.',
            'city.required' => 'Zadejte město.',
            'zip.required' => 'Zadejte PSČ.',
            'country.required' => 'Zadejte zemi.',
            'country.size' => 'Zadejte platný dvoupísmenný kód země.',
            'ico.regex' => 'IČO musí mít 8 číslic.',
            'delivery_name.required_if' => 'Zadejte jméno pro doručení.',
            'delivery_street.required_if' => 'Zadejte ulici pro doručení.',
            'delivery_city.required_if' => 'Zadejte město pro doručení.',
            'delivery_zip.required_if' => 'Zadejte PSČ pro doručení.',
            'terms.accepted' => 'Bez souhlasu s obchodními podmínkami nelze objednávku dokončit.',
        ];
    }

    /**
     * The billing address bag, shaped for orders.billing.
     *
     * @return array<string, mixed>
     */
    public function billingAddress(): array
    {
        return array_filter([
            'name' => (string) $this->string('name'),
            'company' => $this->filled('company') ? (string) $this->string('company') : null,
            'ico' => $this->filled('ico') ? (string) $this->string('ico') : null,
            'dic' => $this->filled('dic') ? (string) $this->string('dic') : null,
            'street' => (string) $this->string('street'),
            'city' => (string) $this->string('city'),
            'zip' => (string) $this->string('zip'),
            'country' => strtoupper((string) $this->string('country')),
        ], fn ($v) => $v !== null);
    }

    /**
     * The delivery address bag, shaped for orders.shipping, or null when it is
     * the same as billing.
     *
     * @return array<string, mixed>|null
     */
    public function deliveryAddress(): ?array
    {
        if (! $this->boolean('ship_to_different')) {
            return null;
        }

        return [
            'name' => (string) $this->string('delivery_name'),
            'street' => (string) $this->string('delivery_street'),
            'city' => (string) $this->string('delivery_city'),
            'zip' => (string) $this->string('delivery_zip'),
            'country' => strtoupper((string) $this->string('delivery_country', 'CZ')),
        ];
    }
}
