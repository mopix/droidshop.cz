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

        $stored = Cache::remember(
            "settings:{$tenantId}:{$module}",
            now()->addHour(),
            fn () => DB::table('settings')
                ->where('tenant_id', $tenantId)
                ->where('module', $module)
                ->pluck('value', 'key')
                ->map(fn ($json) => json_decode($json, true))
                ->all()
        );

        // The schema is the single source of the default. Leaving it to each
        // call site (get('docs', 'due_days', config(...))) means schema and code
        // can disagree about what an untouched shop is running on.
        return [...$this->schemaFor($module)?->defaults() ?? [], ...$stored];
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

    /**
     * Validate the whole set, then write it in one transaction.
     *
     * Per-key writing would leave a form half-applied when the sixth value is
     * rejected — the shop would then be running a mix of the old and the new
     * configuration with nothing on screen saying so.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(string $module, array $values): void
    {
        $tenantId = $this->requireTenant();

        foreach ($values as $key => $value) {
            $this->validate($module, $key, $value);
        }

        DB::transaction(function () use ($tenantId, $module, $values): void {
            foreach ($values as $key => $value) {
                DB::table('settings')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'module' => $module, 'key' => $key],
                    ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });

        $this->forget($module);
    }

    public function forget(string $module): void
    {
        Cache::forget("settings:{$this->requireTenant()}:{$module}");
    }

    /**
     * The settings schema a module declares, or null when it has none.
     */
    public function schemaFor(string $module): ?SettingsSchema
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

        return SettingsSchema::fromArray(json_decode((string) file_get_contents($path), true) ?? []);
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

        if (! $schema->has($key)) {
            throw InvalidSetting::unknownKey($module, $key);
        }

        $validator = Validator::make([$key => $value], [$key => $schema->field($key)->rules]);

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
