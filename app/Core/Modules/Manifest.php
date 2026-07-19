<?php

namespace App\Core\Modules;

use App\Core\Enums\PlanLevel;

/**
 * A module's module.json, parsed (spec §5.1).
 *
 * Readonly: the manifest describes what was deployed. Anything that changes
 * per tenant lives in tenant_modules, never here.
 */
readonly class Manifest
{
    /**
     * @param  array<string, string>  $title  locale => text
     * @param  array<string, string>  $description  locale => text
     * @param  array<string, string>  $requires  module key => semver constraint
     * @param  list<string>  $provides
     * @param  list<string>  $listens
     * @param  list<string>  $permissions
     * @param  list<array<string, mixed>>  $nav
     */
    public function __construct(
        public string $name,
        public string $version,
        public array $title = [],
        public array $description = [],
        public bool $core = false,
        public bool $billable = false,
        public PlanLevel $level = PlanLevel::Base,
        public array $requires = [],
        public array $provides = [],
        public array $listens = [],
        public array $permissions = [],
        public ?string $settingsSchema = null,
        public array $nav = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            version: $data['version'],
            title: $data['title'] ?? [],
            description: $data['description'] ?? [],
            core: $data['core'] ?? false,
            billable: $data['billable'] ?? false,
            level: PlanLevel::from($data['level'] ?? 'base'),
            requires: $data['requires'] ?? [],
            provides: $data['provides'] ?? [],
            listens: $data['listens'] ?? [],
            permissions: $data['permissions'] ?? [],
            settingsSchema: $data['settings_schema'] ?? null,
            nav: $data['nav'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'title' => $this->title,
            'description' => $this->description,
            'core' => $this->core,
            'billable' => $this->billable,
            'level' => $this->level->value,
            'requires' => $this->requires,
            'provides' => $this->provides,
            'listens' => $this->listens,
            'permissions' => $this->permissions,
            'settings_schema' => $this->settingsSchema,
            'nav' => $this->nav,
        ];
    }

    public function titleFor(string $locale = 'cs'): string
    {
        return $this->title[$locale] ?? $this->title['cs'] ?? $this->name;
    }

    /**
     * @return list<string>
     */
    public function dependencyKeys(): array
    {
        return array_keys($this->requires);
    }
}
