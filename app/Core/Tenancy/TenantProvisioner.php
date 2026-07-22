<?php

namespace App\Core\Tenancy;

use App\Core\Enums\TenantStatus;
use App\Core\Modules\ModuleRegistry;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\Exceptions\InvalidSubdomain;
use App\Core\Tenancy\Exceptions\SubdomainTaken;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for standing up a tenant (spec §6.0): tenant row,
 * primary subdomain, owner membership, and the plan's modules — all in one
 * transaction, so a half-created shop can never exist. DemoShopSeeder calls
 * this too; there is no second recipe.
 */
class TenantProvisioner
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly AuditLog $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @throws InvalidSubdomain
     * @throws SubdomainTaken
     */
    public function provision(User $owner, string $shopName, string $subdomainInput, Plan $plan): Tenant
    {
        // Validate BEFORE opening the transaction: a reserved/invalid slug is a
        // caller error, not a rollback case.
        $slug = SubdomainName::fromInput($subdomainInput);
        $host = SubdomainName::host($slug);

        $trialDays = (int) config('billing.trial_days', 14);

        return DB::transaction(function () use ($owner, $shopName, $plan, $host, $trialDays): Tenant {
            $tenant = Tenant::create([
                'name' => $shopName,
                'status' => TenantStatus::Trial,
                'plan_id' => $plan->id,
                'trial_ends_at' => now()->addDays($trialDays),
            ]);

            try {
                Domain::create([
                    'tenant_id' => $tenant->id,
                    'domain' => $host,
                    'type' => 'subdomain',
                    'is_primary' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw SubdomainTaken::host($host);
            }

            $tenant->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

            foreach ($this->modulesFor($plan) as $key) {
                $this->registry->activate($tenant, $key);
            }

            $this->context->runAs($tenant, fn () => $this->audit->log('tenant.provisioned', $tenant, ['host' => $host]));

            return $tenant;
        });
    }

    /**
     * Modules to switch on at creation: everything the plan grants that is
     * actually deployed. Falls back to nothing rather than guessing.
     *
     * @return list<string>
     */
    private function modulesFor(Plan $plan): array
    {
        return $plan->modules()->pluck('module_key')->all();
    }
}
