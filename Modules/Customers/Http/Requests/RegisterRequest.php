<?php

namespace Modules\Customers\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Modules\Customers\Services\CustomerEraser;

class RegisterRequest extends FormRequest
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
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Scoped to this shop: the same address is a legitimate
                // separate account at every other tenant.
                Rule::unique('customers', 'email')
                    ->where('tenant_id', app(TenantContext::class)->id()),
                // Reserved for GDPR erasure placeholders (CustomerEraser).
                // Without this, registering exactly the address a future
                // erase() would generate turns that later GDPR request into
                // a unique-index collision it cannot resolve on its own.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (CustomerEraser::isReservedEmail((string) $value)) {
                        $fail('Tuto e-mailovou adresu nelze použít.');
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Účet s touto e-mailovou adresou už v tomto obchodě existuje.',
            'terms.accepted' => 'Bez souhlasu s podmínkami účet založit nelze.',
        ];
    }
}
