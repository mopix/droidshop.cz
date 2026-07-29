<?php

namespace App\Core\Settings;

/**
 * One configurable value a module exposes: what may be stored in it
 * (`rules`, the authority) and how to draw it (`type`, presentation only).
 */
final readonly class SettingsField
{
    /**
     * @param  array<string, string>  $options  value => label, select only
     */
    public function __construct(
        public string $key,
        public string $rules,
        public string $label,
        public string $type,
        public mixed $default = null,
        public ?string $help = null,
        public array $options = [],
    ) {}
}
