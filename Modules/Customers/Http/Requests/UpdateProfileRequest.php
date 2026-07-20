<?php

namespace Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;
use Modules\Customers\Models\Customer;

/**
 * Validates the account profile form: name, phone, and an optional password
 * change.
 *
 * The password fields are optional as a pair — a visitor updating just their
 * phone number must not be forced to retype a password. But the moment a new
 * password is present, the current one becomes required and must check out:
 * the account page stays reachable for as long as the session lives, and a
 * password change is the one field on it a hijacked session could otherwise
 * use to lock the real owner out for good.
 */
class UpdateProfileRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'current_password' => ['nullable', 'required_with:password', 'string'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->wantsPasswordChange()) {
                return;
            }

            /** @var Customer $customer */
            $customer = $this->user('customer');

            if (! Hash::check((string) $this->string('current_password'), $customer->password)) {
                $validator->errors()->add('current_password', 'Zadané současné heslo není správné.');
            }
        });
    }

    public function wantsPasswordChange(): bool
    {
        return $this->filled('password');
    }
}
