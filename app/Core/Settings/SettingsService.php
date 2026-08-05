<?php

namespace App\Core\Settings;

use App\Core\Modules\Manifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Core\Settings\Exceptions\InvalidSetting;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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
        private readonly Generations $generations,
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
        return $this->decryptSecrets($module, [...$this->schemaFor($module)?->defaults() ?? [], ...$stored]);
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

        // A secret submitted blank means "leave it alone", not "erase it" —
        // the admin screen never shows the stored value back, so a blank box
        // is what an untouched field looks like. Same keep-on-update rule the
        // Comgate and Packeta credential forms already follow.
        $values = $this->dropUntouchedSecrets($module, $values);

        foreach ($values as $key => $value) {
            $this->validate($module, $key, $value);
        }

        $values = $this->encryptSecrets($module, $values);

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

    /**
     * Which keys of this module are credentials.
     *
     * @return list<string>
     */
    private function secretKeys(string $module): array
    {
        $schema = $this->schemaFor($module);

        if ($schema === null) {
            return [];
        }

        // fields() is array_values()'d, so its keys are positions, not field
        // names — the name has to come off the field itself.
        return array_values(array_map(
            fn (SettingsField $field) => $field->key,
            array_filter($schema->fields(), fn (SettingsField $field) => $field->secret),
        ));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function dropUntouchedSecrets(string $module, array $values): array
    {
        foreach ($this->secretKeys($module) as $key) {
            if (array_key_exists($key, $values) && (string) $values[$key] === '') {
                unset($values[$key]);
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function encryptSecrets(string $module, array $values): array
    {
        foreach ($this->secretKeys($module) as $key) {
            if (array_key_exists($key, $values) && is_string($values[$key]) && $values[$key] !== '') {
                $values[$key] = Crypt::encryptString($values[$key]);
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function decryptSecrets(string $module, array $values): array
    {
        foreach ($this->secretKeys($module) as $key) {
            if (! is_string($values[$key] ?? null) || $values[$key] === '') {
                continue;
            }

            try {
                $values[$key] = Crypt::decryptString($values[$key]);
            } catch (DecryptException) {
                // A value written before the field became a secret, or after
                // an APP_KEY rotation. Reading it as plaintext would hand the
                // caller ciphertext and let it be sent to a third party as if
                // it were the credential; an empty string makes the feature
                // fail closed and the tenant re-enter it.
                $values[$key] = '';
            }
        }

        return $values;
    }

    public function forget(string $module): void
    {
        $tenantId = $this->requireTenant();

        Cache::forget("settings:{$tenantId}:{$module}");

        // Page cache (wave 3.0): settings reach the rendered storefront (the
        // variant picker, the order number prefix, the checkout minimum), so
        // a page cached before this edit must not survive it. set() and
        // setMany() both funnel through here, so bumping in this one place
        // covers both without duplicating the call at each write path — and
        // covers any future direct caller of forget() too.
        //
        // requireTenant() above already throws when there is no ambient
        // tenant, so context->current() here is guaranteed non-null.
        //
        // Not deferred with DB::afterCommit: setMany()'s own DB::transaction()
        // has already closed by the time this runs (forget() is called after
        // it, not from inside it), so the write is already durable. Nothing
        // in this codebase calls set()/setMany() from inside a longer,
        // externally-owned transaction — unlike an order placement, an admin
        // saving a settings form is a single low-frequency write with nothing
        // else contending for the tenant row around it.
        $this->generations->bump($this->context->current(), Dimension::Theme);
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
