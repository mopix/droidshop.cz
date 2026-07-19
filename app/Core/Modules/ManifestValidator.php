<?php

namespace App\Core\Modules;

use App\Core\Modules\Exceptions\InvalidManifest;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Validates module.json before anything is written to the registry.
 */
class ManifestValidator
{
    public function __construct(private readonly VersionParser $versionParser = new VersionParser) {}

    /**
     * @throws InvalidManifest
     */
    public function validateFile(string $path): Manifest
    {
        if (! is_file($path)) {
            throw InvalidManifest::unreadable($path);
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            throw InvalidManifest::unreadable($path);
        }

        return $this->validate($data, $path);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidManifest
     */
    public function validate(array $data, string $path = 'module.json'): Manifest
    {
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'regex:/^[a-z][a-z0-9\-]*$/'],
            'version' => ['required', 'string'],
            'title' => ['sometimes', 'array'],
            'title.*' => ['string'],
            'description' => ['sometimes', 'array'],
            'description.*' => ['string'],
            'core' => ['sometimes', 'boolean'],
            'billable' => ['sometimes', 'boolean'],
            'level' => ['sometimes', 'in:base,premium'],
            'requires' => ['sometimes', 'array'],
            'provides' => ['sometimes', 'array'],
            'provides.*' => ['string'],
            'listens' => ['sometimes', 'array'],
            'listens.*' => ['string'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
            'settings_schema' => ['sometimes', 'nullable', 'string'],
            'nav' => ['sometimes', 'array'],
            'nav.*.area' => ['required_with:nav', 'in:admin,storefront'],
            'nav.*.label' => ['required_with:nav', 'string'],
            'nav.*.route' => ['required_with:nav', 'string'],
            'nav.*.order' => ['sometimes', 'integer'],
        ], [
            'name.regex' => 'must be a lowercase slug (letters, digits, hyphens).',
        ]);

        $validator->after(function ($validator) use ($data): void {
            $this->checkVersion($validator, $data);
            $this->checkRequires($validator, $data);
        });

        if ($validator->fails()) {
            throw InvalidManifest::forPath($path, $validator->errors()->toArray());
        }

        return Manifest::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkVersion(mixed $validator, array $data): void
    {
        if (! isset($data['version']) || ! is_string($data['version'])) {
            return;
        }

        try {
            $this->versionParser->normalize($data['version']);
        } catch (Throwable) {
            $validator->errors()->add('version', "[{$data['version']}] is not a valid version.");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkRequires(mixed $validator, array $data): void
    {
        foreach ($data['requires'] ?? [] as $module => $constraint) {
            if (! is_string($module) || ! is_string($constraint)) {
                $validator->errors()->add('requires', 'must map module keys to version constraints.');

                continue;
            }

            try {
                $this->versionParser->parseConstraints($constraint);
            } catch (Throwable) {
                $validator->errors()->add('requires', "[{$constraint}] is not a valid constraint for [{$module}].");
            }
        }
    }
}
