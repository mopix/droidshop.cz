<?php

namespace Modules\Orders;

use App\Core\Modules\Contracts\ModuleLifecycle;
use App\Core\Sequences\SequenceService;
use App\Models\Tenant;

class Lifecycle implements ModuleLifecycle
{
    /**
     * Prepares the tenant's order-number series.
     *
     * SequenceService::next() would lazily create the series with these same
     * defaults (empty prefix, starting at 1) on its own first call, so this
     * is a courtesy rather than a strict requirement — but doing it here
     * means the series exists (and is inspectable) from the moment the shop
     * turns orders on, not from the moment its first order is placed.
     *
     * Deliberately does not run from Modules\Orders\Providers\ModuleProvider
     * ::boot(): that fires on every request/command before SetTenantContext
     * has run, with no tenant resolved yet — SequenceService::configure()
     * would throw MissingTenantContext on the very first request. This hook
     * runs inside ModuleRegistry::activate()'s own runAs($tenant, ...), the
     * one place a tenant is already guaranteed to be in context at the
     * moment a module is switched on.
     *
     * configure() is an updateOrInsert, so calling it again on a
     * deactivate/reactivate cycle is safe in the sense the interface
     * promises (it does not throw or duplicate a row) — though note it does
     * reset next_number back to startAt each time, which only matters if a
     * tenant is reactivated after already having placed orders.
     */
    public function onActivate(Tenant $tenant): void
    {
        app(SequenceService::class)->configure('orders', prefix: '', startAt: 1);
    }

    /**
     * Nothing to do: deactivation hides the module, the tenant's orders and
     * their number series stay exactly where they are.
     */
    public function onDeactivate(Tenant $tenant): void
    {
        //
    }
}
