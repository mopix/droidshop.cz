<?php

namespace App\Core\Settings;

use App\Core\Modules\Manifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\Exceptions\InvalidSetting;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Per-tenant module settings (spec §15.1).
 *
 * Values are scoped to the current tenant and validated against the schema the
 * module declares in its manifest. The whole set for a module is cached under
 * settings:{tenant}:{module} and invalidated on write.
 */
class SettingsService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ModuleRegistry $registry,
    ) {}

    public function get(string $module, string $key, mixed $default = null): mixed
    {
        return $this->all($module)[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(string $module): array
    {
        $tenantId = $this->requireTenant();

        return Cache::remember(
            "settings:{$tenantId}:{$module}",
            now()->addHour(),
            fn () => DB::table('settings')
                ->where('tenant_id', $tenantId)
                ->where('module', $module)
                ->pluck('value', 'key')
                ->map(fn ($json) => json_decode($json, true))
                ->all()
        );
    }

    public function set(string $module, string $key, mixed $value): void
    {
        $tenantId = $this->requireTenant();

        $this->validate($module, $key, $value);

        DB::table('settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'module' => $module, 'key' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
        );

        $this->forget($module);
    }

    public function forget(string $module): void
    {
        Cache::forget("settings:{$this->requireTenant()}:{$module}");
    }

    /**
     * The JSON-schema-ish rules a module declares for its settings, or null.
     *
     * @return array<string, mixed>|null
     */
    public function schemaFor(string $module): ?array
    {
        $model = $this->registry->all()->get($module);

        if (! $model) {
            return null;
        }

        $manifest = Manifest::fromArray($model->manifest);

        if ($manifest->settingsSchema === null) {
            return null;
        }

        $path = base_path('Modules/'.str($module)->studly().'/'.$manifest->settingsSchema);

        if (! is_file($path)) {
            return null;
        }

        return json_decode((string) file_get_contents($path), true);
    }

    private function validate(string $module, string $key, mixed $value): void
    {
        $schema = $this->schemaFor($module);

        if ($schema === null) {
            // No schema is a real gap, not a green light: an unvalidated
            // setting can hold anything. Warn so it surfaces, then allow.
            Log::warning("Module [{$module}] has no settings schema; [{$key}] stored unvalidated.");

            return;
        }

        if (! isset($schema[$key])) {
            throw InvalidSetting::unknownKey($module, $key);
        }

        $validator = Validator::make([$key => $value], [$key => $schema[$key]]);

        if ($validator->fails()) {
            throw InvalidSetting::failedValidation($module, $key, $validator->errors()->first($key));
        }
    }

    private function requireTenant(): int
    {
        $id = $this->context->id();

        if ($id === null) {
            throw MissingTenantContext::forModel('settings');
        }

        return $id;
    }
}
