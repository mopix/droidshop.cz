<?php

namespace App\Core\Modules;

use App\Core\Modules\Exceptions\UnresolvableDependencies;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\TenantContext;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The registry of modules and who has them switched on (spec §15.1, §15.5).
 */
class ModuleRegistry
{
    /**
     * Short on purpose: the kill switch has to take effect quickly, and this
     * is read on every request.
     */
    private const CACHE_TTL = 60;

    public function __construct(
        private readonly TenantContext $context,
        private readonly DependencyResolver $resolver,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Every deployed module, whatever its global state.
     *
     * @return Collection<string, Module>
     */
    public function all(): Collection
    {
        return Cache::remember('modules:registry', self::CACHE_TTL,
            fn () => Module::query()->orderBy('key')->get()->keyBy('key')
        );
    }

    /**
     * Modules that are globally live, in dependency order.
     *
     * @return Collection<string, Module>
     */
    public function available(): Collection
    {
        $live = $this->all()->filter->enabled_globally;

        $manifests = $live->map(fn (Module $m) => Manifest::fromArray($m->manifest))->all();

        return collect($this->resolver->sort($manifests))
            ->mapWithKeys(fn (string $key) => [$key => $live[$key]]);
    }

    /**
     * Modules this tenant actually runs, in dependency order.
     *
     * Core modules are always in: they are what the shop is made of, not an
     * option. The kill switch still outranks them — a module withdrawn
     * platform-wide is off for everyone.
     *
     * @return Collection<string, Module>
     */
    public function enabledFor(Tenant $tenant): Collection
    {
        $enabledKeys = Cache::remember(
            "modules:enabled:{$tenant->id}",
            self::CACHE_TTL,
            fn () => $this->context->runAs($tenant, fn () => TenantModule::query()
                ->where('enabled', true)
                ->pluck('module_key')
                ->all()
            )
        );

        return $this->available()->filter(
            fn (Module $module) => $module->core || in_array($module->key, $enabledKeys, true)
        );
    }

    public function isEnabled(Tenant $tenant, string $key): bool
    {
        return $this->enabledFor($tenant)->has($key);
    }

    /**
     * @throws UnresolvableDependencies
     */
    public function activate(Tenant $tenant, string $key): void
    {
        $module = $this->available()->get($key);

        if (! $module) {
            throw UnresolvableDependencies::missing('tenant '.$tenant->id, $key);
        }

        $this->guardDependencies($tenant, $module);

        // Everything below runs inside the tenant: the lifecycle hook seeds
        // tenant-scoped data, and the audit entry has to carry the tenant it
        // belongs to. Logging outside would file it as a platform action.
        $this->context->runAs($tenant, function () use ($module, $tenant): void {
            TenantModule::updateOrCreate(
                ['module_key' => $module->key],
                ['enabled' => true, 'activated_at' => now(), 'deactivated_at' => null],
            );

            $this->forgetTenant($tenant);

            $this->lifecycleFor($module)?->onActivate($tenant);

            $this->audit->log('module.activated', $module, ['module' => $module->key]);
        });
    }

    /**
     * Deactivation hides the module but keeps its data: spec §5.2 makes it a
     * reversible operation, and a tenant switching something off by mistake
     * must not lose anything.
     */
    public function deactivate(Tenant $tenant, string $key): void
    {
        $module = $this->all()->get($key);

        if (! $module) {
            return;
        }

        if ($module->core) {
            throw new \InvalidArgumentException("Module [{$key}] is a core module and cannot be switched off.");
        }

        $this->guardDependents($tenant, $key);

        $this->context->runAs($tenant, function () use ($key, $module, $tenant): void {
            TenantModule::query()
                ->where('module_key', $key)
                ->update(['enabled' => false, 'deactivated_at' => now()]);

            $this->forgetTenant($tenant);

            $this->lifecycleFor($module)?->onDeactivate($tenant);

            $this->audit->log('module.deactivated', $module, ['module' => $key]);
        });
    }

    public function flush(): void
    {
        Cache::forget('modules:registry');
    }

    public function forgetTenant(Tenant $tenant): void
    {
        Cache::forget("modules:enabled:{$tenant->id}");
    }

    /**
     * @throws UnresolvableDependencies
     */
    private function guardDependencies(Tenant $tenant, Module $module): void
    {
        $manifest = Manifest::fromArray($module->manifest);

        $available = $this->available()
            ->map(fn (Module $m) => Manifest::fromArray($m->manifest))
            ->all();

        $problems = $this->resolver->unmetDependencies($manifest, $available);

        if ($problems !== []) {
            throw new UnresolvableDependencies(implode(' ', $problems));
        }

        // Turning something on must not leave it half-working, so anything it
        // needs gets switched on with it.
        foreach ($manifest->dependencyKeys() as $dependency) {
            if (! $this->isEnabled($tenant, $dependency)) {
                $this->activate($tenant, $dependency);
            }
        }
    }

    /**
     * Refuses to switch off a module that another enabled module needs.
     */
    private function guardDependents(Tenant $tenant, string $key): void
    {
        foreach ($this->enabledFor($tenant) as $candidate) {
            if ($candidate->key === $key) {
                continue;
            }

            $manifest = Manifest::fromArray($candidate->manifest);

            if (in_array($key, $manifest->dependencyKeys(), true)) {
                throw new \InvalidArgumentException(
                    "Module [{$key}] cannot be switched off: [{$candidate->key}] depends on it."
                );
            }
        }
    }

    private function lifecycleFor(Module $module): ?Contracts\ModuleLifecycle
    {
        $class = 'Modules\\'.str($module->key)->studly().'\\Lifecycle';

        if (! class_exists($class)) {
            return null;
        }

        $instance = app($class);

        return $instance instanceof Contracts\ModuleLifecycle ? $instance : null;
    }
}
