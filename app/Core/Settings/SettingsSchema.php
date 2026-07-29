<?php

namespace App\Core\Settings;

/**
 * A module's settings.json, parsed.
 *
 * Two shapes are accepted. A plain string is the original wave-1.5 form and
 * means rules with no presentation metadata; an object carries the label,
 * field type, default and select options the admin form needs. Keeping the
 * old shape valid is what allows a module to be migrated on its own schedule
 * rather than all thirteen at once.
 */
final readonly class SettingsSchema
{
    /**
     * @param  array<string, SettingsField>  $fields
     */
    private function __construct(private array $fields) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $fields = [];

        foreach ($raw as $key => $definition) {
            $definition = is_array($definition) ? $definition : ['rules' => (string) $definition];
            $rules = (string) ($definition['rules'] ?? '');

            $fields[$key] = new SettingsField(
                key: $key,
                rules: $rules,
                label: (string) ($definition['label'] ?? $key),
                type: (string) ($definition['type'] ?? self::deriveType($rules)),
                default: $definition['default'] ?? null,
                help: $definition['help'] ?? null,
                options: $definition['options'] ?? [],
            );
        }

        return new self($fields);
    }

    /**
     * Best-effort presentation type for a legacy schema that only has rules.
     * Never used to decide what may be stored — that stays with the rules.
     */
    private static function deriveType(string $rules): string
    {
        $parts = explode('|', $rules);

        foreach ($parts as $part) {
            if ($part === 'boolean') {
                return 'boolean';
            }

            if (str_starts_with($part, 'in:')) {
                return 'select';
            }

            if ($part === 'integer' || $part === 'numeric') {
                return 'number';
            }
        }

        // A long free-text limit reads as prose, not as a one-line value.
        foreach ($parts as $part) {
            if (str_starts_with($part, 'max:') && (int) substr($part, 4) > 255) {
                return 'textarea';
            }
        }

        return 'text';
    }

    /**
     * @return list<SettingsField>
     */
    public function fields(): array
    {
        return array_values($this->fields);
    }

    public function field(string $key): ?SettingsField
    {
        return $this->fields[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return array_map(static fn (SettingsField $field): string => $field->rules, $this->fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return array_filter(
            array_map(static fn (SettingsField $field): mixed => $field->default, $this->fields),
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
