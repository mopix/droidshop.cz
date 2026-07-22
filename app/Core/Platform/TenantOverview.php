<?php

namespace App\Core\Platform;

use App\Core\Limits\LimitsService;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use App\Models\AuditLogEntry;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantModule;

/**
 * Everything the superadmin detail screen shows about one tenant.
 *
 * Assembled here rather than in the controller because half of it lives behind
 * the tenant scope: TenantModule and anything a module owns are invisible — or
 * worse, wrong — unless the query runs inside TenantContext::runAs.
 */
class TenantOverview
{
    /** How much of the trail the detail screen shows before it needs its own page. */
    private const AUDIT_ENTRIES = 20;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ModuleRegistry $registry,
        private readonly LimitsService $limits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Tenant $tenant): array
    {
        $tenant->loadMissing(['plan', 'domains', 'users']);

        return [
            'tenant' => $this->basics($tenant),
            'domains' => $this->domains($tenant),
            'users' => $this->users($tenant),
            'modules' => $this->modules($tenant),
            'limits' => $this->limitUsage($tenant),
            'audit' => $this->audit($tenant),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function basics(Tenant $tenant): array
    {
        return [
            // Numeric id alongside the uuid: impersonation addresses tenant and
            // user by id, and this payload never leaves the platform host.
            'id' => $tenant->id,
            'uuid' => $tenant->uuid,
            'name' => $tenant->name,
            'status' => $tenant->status->value,
            'status_label' => $tenant->status->label(),
            'plan_id' => $tenant->plan_id,
            'plan' => $tenant->plan?->only('id', 'key', 'name'),
            'trial_ends_at' => $tenant->trial_ends_at?->toDateTimeString(),
            // Same "paid through" reading the tenant-facing subscription
            // screen uses (Tenant/SubscriptionController::show): trial_ends_at
            // doubles as the current period end once a subscription starts.
            'paid_through' => $tenant->trial_ends_at?->toDateString(),
            'stripe_customer_id' => $tenant->stripe_customer_id,
            'stripe_subscription_id' => $tenant->stripe_subscription_id,
            'suspended_at' => $tenant->suspended_at?->toDateTimeString(),
            'deletion_requested_at' => $tenant->deletion_requested_at?->toDateTimeString(),
            'billing_name' => $tenant->billing_name,
            'billing_ico' => $tenant->billing_ico,
            'billing_dic' => $tenant->billing_dic,
            'country' => $tenant->country,
            'currency' => $tenant->currency,
            'created_at' => $tenant->created_at?->toDateTimeString(),
            'allows_storefront' => $tenant->allowsStorefront(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function domains(Tenant $tenant): array
    {
        return $tenant->domains
            ->sortByDesc('is_primary')
            ->map(fn ($domain) => [
                'domain' => $domain->domain,
                'type' => $domain->type,
                'is_primary' => (bool) $domain->is_primary,
                'ssl_status' => $domain->ssl_status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function users(Tenant $tenant): array
    {
        return $tenant->users
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'joined_at' => $user->pivot->joined_at,
            ])
            ->all();
    }

    /**
     * Every deployed module with this tenant's state on it, so the screen can
     * offer activation and deactivation from one list.
     *
     * @return list<array<string, mixed>>
     */
    private function modules(Tenant $tenant): array
    {
        $enabled = $this->registry->enabledFor($tenant);

        $inPlan = $tenant->plan
            ? $tenant->plan->modules()->pluck('modules.key')->all()
            : [];

        return $this->registry->all()
            ->map(fn (Module $module) => [
                'key' => $module->key,
                'name' => $module->manifest['title']['cs'] ?? $module->key,
                'version' => $module->version,
                'core' => (bool) $module->core,
                'enabled' => $enabled->has($module->key),
                'enabled_globally' => (bool) $module->enabled_globally,
                'in_plan' => $module->core || in_array($module->key, $inPlan, true),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function limitUsage(Tenant $tenant): array
    {
        $keys = array_keys($tenant->plan?->limits ?? []);

        // Delta 0: we are reporting where the tenant stands, not asking whether
        // one more of something would fit.
        return $this->context->runAs($tenant, fn () => array_map(function (string $key) {
            $result = $this->limits->check($key, 0);

            return [
                'key' => $key,
                'used' => $result->used,
                'cap' => $result->cap,
                'outcome' => $result->outcome->value,
            ];
        }, $keys));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function audit(Tenant $tenant): array
    {
        return AuditLogEntry::query()
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->limit(self::AUDIT_ENTRIES)
            ->get()
            ->map(fn (AuditLogEntry $entry) => [
                'action' => $entry->action,
                'meta' => $entry->meta,
                'ip' => $entry->ip,
                'created_at' => $entry->created_at,
            ])
            ->all();
    }

    /**
     * Modules the tenant has switched on that a given plan would not allow.
     * Used both for the downgrade preview and by PlanSwitcher itself.
     *
     * @return list<string>
     */
    public function modulesLostOnPlan(Tenant $tenant, ?int $planId): array
    {
        $allowed = $planId === null
            ? []
            : Plan::findOrFail($planId)->modules()->pluck('modules.key')->all();

        return $this->context->runAs($tenant, fn () => TenantModule::query()
            ->where('enabled', true)
            ->pluck('module_key')
            ->reject(fn (string $key) => in_array($key, $allowed, true))
            ->reject(fn (string $key) => (bool) ($this->registry->all()->get($key)?->core))
            ->values()
            ->all()
        );
    }
}
