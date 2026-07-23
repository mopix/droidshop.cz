<?php

namespace App\Http\Requests\Tenant;

use App\Core\Enums\DomainType;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A tenant attaching their own domain (wave 2.1). Unlike a subdomain
 * (`SubdomainName`), this is a foreign apex we do not control the naming of
 * — validation only shapes the FQDN and rejects anything that would collide
 * with the platform itself.
 */
class AddCustomDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // tenant.member already gated the route
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'domain' => mb_strtolower(trim((string) $this->input('domain'))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
                Rule::unique('domains', 'domain'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $platform = (string) config('tenancy.platform_domain');

                    if ($value === $platform || Str::endsWith($value, '.'.$platform)) {
                        $fail('Vlastní doména nesmí být subdoménou platformy.');
                    }
                },
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $tenantId = app(TenantContext::class)->id();

                    $hasCustom = Domain::query()
                        ->where('tenant_id', $tenantId)
                        ->where('type', DomainType::Custom)
                        ->exists();

                    if ($hasCustom) {
                        $fail('Tento e-shop už má nastavenou vlastní doménu. Nejprve odeberte tu stávající.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.required' => 'Zadejte doménu.',
            'domain.max' => 'Doména je příliš dlouhá.',
            'domain.regex' => 'Zadejte platnou doménu, např. muj-eshop.cz.',
            'domain.unique' => 'Tuto doménu už používá jiný e-shop.',
        ];
    }
}
