<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            // Server-side, not just a `required` attribute on the checkbox:
            // the consent recorded on users.terms_accepted_at is meant to be
            // evidence, and evidence a client can skip is not evidence.
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terms.accepted' => 'Bez souhlasu s obchodními podmínkami se nelze zaregistrovat.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'jméno',
            'email' => 'e-mail',
            'password' => 'heslo',
            'terms' => 'souhlas s podmínkami',
        ];
    }
}
