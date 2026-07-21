<?php

namespace Modules\Checkout\Http\Requests;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Shipping\Contracts\PaymentOptions;
use App\Core\Shipping\Contracts\ShippingOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Checkout\Services\CartPricer;
use Modules\Checkout\Support\CartCookie;

/**
 * Choosing a shipping method — and, once one is picked, a payment method —
 * on `/pokladna/doprava`.
 *
 * Structural rules only check shape (an integer id); which ids are actually
 * legal is a tenant- and cart-specific question (is this shipping id among
 * ShippingOptions::available() for this cart's weight, is this payment id
 * among PaymentOptions::forShipping() for the chosen shipping method), so
 * it is checked here in a validator-after closure against those same
 * contracts, not with a raw `exists` rule — `exists` queries the table
 * directly and would bypass the tenant scope ShippingOptions/PaymentOptions
 * apply internally, the same reason AddCartItemRequest avoids it for
 * product ids. A spoofed price never enters into this at all: no price
 * field is declared, and the ids validated here are only ever used to look
 * an option up again server-side, never to charge what the request itself
 * claims (AK 5).
 */
class ChooseShippingRequest extends FormRequest
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
            // Nullable at this level on purpose: when the shipping module is
            // off (or deactivated) for this tenant, ShippingOptions::available()
            // is empty and there is no real id to submit at all — the closure
            // below is what actually makes this required whenever a real
            // option set exists to choose from.
            'shipping_method_id' => ['nullable', 'integer', 'min:1'],
            'payment_method_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shipping_method_id.integer' => 'Vyberte způsob dopravy.',
            'payment_method_id.integer' => 'Vyberte způsob platby.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Read-only lookup, never a mutating one: forToken() only runs
            // when a cart cookie is already present, so a bare POST with no
            // cart yet cannot mint a stray row here that the controller's
            // own forToken() call (on the same request) would never see
            // again. No cookie simply means an empty cart — weight 0 — which
            // is the correct input to available() for it anyway.
            $token = CartCookie::read($this);

            $weightGrams = $token === null
                ? 0
                : app(CartPricer::class)->weightGrams(app(CartRepository::class)->forToken($token));

            $available = app(ShippingOptions::class)->available($weightGrams);

            if ($available->isEmpty()) {
                // Nothing to validate a submission against: either the shop
                // never turned shipping on (the fallback page has no form to
                // begin with) or this is a stale submission from before the
                // module was switched off. Either way, an id the server
                // cannot resolve to anything is rejected, not silently kept.
                $validator->errors()->add('shipping_method_id', 'Doprava momentálně není k dispozici.');

                return;
            }

            $shippingId = $this->integer('shipping_method_id');

            if (! $this->filled('shipping_method_id') || $available->doesntContain(fn ($option) => $option->id() === $shippingId)) {
                $validator->errors()->add('shipping_method_id', 'Vyberte platnou dopravu.');

                return;
            }

            if ($this->filled('payment_method_id')) {
                $paymentId = $this->integer('payment_method_id');
                $payments = app(PaymentOptions::class)->forShipping($shippingId);

                if ($payments->doesntContain(fn ($option) => $option->id() === $paymentId)) {
                    $validator->errors()->add('payment_method_id', 'Vyberte platnou platbu.');
                }
            }
        });
    }
}
