<?php

namespace App\Core\Billing;

use App\Core\Billing\Contracts\SubscriptionGateway;
use App\Core\Billing\Exceptions\ChargeFailed;
use App\Core\Billing\Models\PlatformInvoice;
use App\Core\Billing\Support\SubscriptionCharge;
use App\Core\Enums\TenantStatus;
use App\Models\Tenant;

/**
 * Converts a tenant to a paid subscription: charge (design-for via the null
 * gateway in 1.7), then — only on success — issue the ledger invoice, flip the
 * tenant to active, and extend the paid-through date. The invoice is the
 * consequence of a settled charge, never the other way round.
 */
class SubscriptionActivator
{
    public function __construct(
        private readonly SubscriptionGateway $gateway,
        private readonly PlatformInvoiceWriter $writer,
    ) {}

    public function activate(Tenant $tenant): PlatformInvoice
    {
        $plan = $tenant->plan;
        if ($plan === null) {
            throw ChargeFailed::reason('tenant has no plan');
        }

        $charge = new SubscriptionCharge($tenant, $plan, now()->startOfMonth(), now()->endOfMonth());

        $result = $this->gateway->charge($charge);
        if (! $result->success) {
            throw ChargeFailed::reason($result->failureReason ?? 'unknown');
        }

        // Issue first: if PDF/number allocation fails we have not yet claimed
        // the tenant is active. MissingBillingProfile surfaces here.
        $invoice = $this->writer->issue($charge);

        $tenant->changeStatus(TenantStatus::Active, 'subscription charged '.$result->reference);
        $tenant->forceFill(['trial_ends_at' => now()->addMonth()])->save();

        return $invoice;
    }
}
