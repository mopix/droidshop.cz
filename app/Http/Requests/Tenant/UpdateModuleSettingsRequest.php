<?php

namespace App\Http\Requests\Tenant;

use App\Core\Money\Exceptions\InvalidMoneyInput;
use App\Core\Money\MoneyInput;
use App\Core\Settings\SettingsSchema;
use App\Core\Settings\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Rules come from the module's own schema, so the form can never accept a key
 * or a value the module did not declare. `authorize()` stays true: which module
 * and which permission is decided by the controller, which is also the only
 * place that knows whether the shop runs the module at all.
 */
class UpdateModuleSettingsRequest extends FormRequest
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
        $schema = app(SettingsService::class)->schemaFor((string) $this->route('module'));

        if ($schema === null) {
            // The controller turns this into a 404; refusing every value here
            // means a schema-less module cannot be written to even if that
            // ordering ever changes.
            return ['values' => ['array', 'size:0']];
        }

        $rules = ['values' => ['required', 'array', $this->rejectKeysOutsideSchema($schema)]];

        foreach ($schema->rules() as $key => $fieldRules) {
            $rules['values.'.$key] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Money fields arrive as korunas and are validated as haléře (wave 3.8).
     *
     * Here rather than in the controller: the schema's own rule for such a
     * field is `integer|min:0`, so `1000,50` would be refused before the
     * controller ever saw it.
     */
    protected function prepareForValidation(): void
    {
        $schema = app(SettingsService::class)->schemaFor((string) $this->route('module'));

        if ($schema === null) {
            return;
        }

        $values = $this->input('values');

        if (! is_array($values)) {
            return;
        }

        foreach ($schema->fields() as $field) {
            if ($field->type !== 'money' || ! array_key_exists($field->key, $values)) {
                continue;
            }

            try {
                $values[$field->key] = MoneyInput::toMinorUnits($values[$field->key]) ?? 0;
            } catch (InvalidMoneyInput) {
                throw ValidationException::withMessages([
                    'values.'.$field->key => 'Zadejte částku v korunách, například 1000,50.',
                ]);
            }
        }

        $this->merge(['values' => $values]);
    }

    /**
     * Anything outside the schema is a hard failure, not a silently dropped
     * field: a typo'd key would otherwise look saved.
     */
    private function rejectKeysOutsideSchema(SettingsSchema $schema): callable
    {
        return static function (string $attribute, mixed $value, callable $fail) use ($schema): void {
            foreach (array_keys((array) $value) as $key) {
                if (! $schema->has((string) $key)) {
                    $fail("Neznámé nastavení [{$key}].");
                }
            }
        };
    }
}
