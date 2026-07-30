<?php

namespace App\Http\Requests\Tenant;

use App\Core\Settings\SettingsSchema;
use App\Core\Settings\SettingsService;
use Illuminate\Foundation\Http\FormRequest;

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
