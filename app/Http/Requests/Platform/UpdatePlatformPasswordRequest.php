<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UpdatePlatformPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * Checked here rather than with the `current_password` rule, which
     * validates against the default guard — this account lives on `platform`
     * and would sail through unverified.
     */
    protected function passedValidation(): void
    {
        if (! Hash::check($this->string('current_password')->toString(), $this->user('platform')->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Zadané heslo není správné.',
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'současné heslo',
            'password' => 'nové heslo',
        ];
    }
}
