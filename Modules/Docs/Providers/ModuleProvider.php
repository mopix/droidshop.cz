<?php

namespace Modules\Docs\Providers;

use App\Core\Documents\Contracts\DocumentIssuer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Docs\Listeners\IssueInvoiceOnPaid;
use Modules\Docs\Listeners\IssueInvoiceOnShipped;
use Modules\Docs\Services\InvoiceIssuer;
use Modules\Orders\Events\OrderPaymentSettled;
use Modules\Orders\Events\OrderShipped;

/**
 * Overrides the kernel's NullDocumentIssuer with the real issuer at deploy
 * level. The per-tenant "is the module active" question is answered at call
 * time by ShopModules inside InvoiceIssuer, not here — this binding is per
 * deploy, matching Modules\Orders\Providers\ModuleProvider.
 */
class ModuleProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Auto-issuing an invoice is a post-commit side effect of an order's
        // payment/fulfillment transition — see OrderPaymentSettled/OrderShipped.
        // Wiring the listeners here, at deploy level, keeps OrderWorkflow
        // entirely unaware of docs: it commits the transition and dispatches,
        // this module decides (via auto_issue_on) whether to issue.
        Event::listen(OrderPaymentSettled::class, IssueInvoiceOnPaid::class);
        Event::listen(OrderShipped::class, IssueInvoiceOnShipped::class);
    }

    public function register(): void
    {
        $this->app->bind(DocumentIssuer::class, InvoiceIssuer::class);
    }
}
