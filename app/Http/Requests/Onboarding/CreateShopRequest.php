<?php

namespace App\Http\Requests\Onboarding;

use App\Core\Tenancy\SubdomainName;
use App\Models\Domain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'shop_name' => ['required', 'string', 'max:255'],
            'subdomain' => ['required', 'string'],
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_public', true)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $slug = SubdomainName::normalise((string) $this->input('subdomain'));

            if (! SubdomainName::isValidFormat($slug)) {
                $validator->errors()->add('subdomain', 'Neplatný formát subdomény (3–63 znaků, a–z, 0–9, pomlčka).');

                return;
            }

            if (SubdomainName::isReserved($slug)) {
                $validator->errors()->add('subdomain', 'Tato subdoména je rezervovaná.');

                return;
            }

            if (Domain::where('domain', SubdomainName::host($slug))->exists()) {
                $validator->errors()->add('subdomain', 'Tato subdoména je již obsazená.');
            }
        });
    }
}
