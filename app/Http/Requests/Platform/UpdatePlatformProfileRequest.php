<?php

namespace App\Http\Requests\Platform;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdatePlatformProfileRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique(PlatformAdmin::class)->ignore($this->user('platform')->id),
            ],
            // Only asked for when the address actually changes — see below.
            'current_password' => ['nullable', 'string'],
        ];
    }

    /**
     * Changing the address of the account that administers the whole platform
     * takes the current password.
     *
     * Being signed in is not enough: an unattended session would otherwise be
     * enough to move password resets to an attacker's mailbox, which is the
     * whole account. The name is not worth the friction, so the check is tied
     * to the address specifically.
     */
    protected function passedValidation(): void
    {
        $admin = $this->user('platform');

        if ($this->string('email')->toString() === $admin->email) {
            return;
        }

        if (! Hash::check((string) $this->input('current_password'), $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Pro změnu e-mailu zadejte své současné heslo.',
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'jméno',
            'email' => 'e-mail',
            'current_password' => 'současné heslo',
        ];
    }
}
