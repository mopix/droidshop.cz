<?php

namespace Modules\Docs\Services;

use App\Core\Orders\Contracts\OrderView;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use Modules\Docs\Models\Document;
use Modules\Docs\Services\Contracts\TypedDocumentIssuer;

/**
 * The invoice type's rule (spec §16.6). The shared write mechanics moved to
 * DocumentWriter in wave 1.6; this class now only describes what an invoice
 * snapshot is and which series/prefix it draws from.
 */
class InvoiceIssuer implements TypedDocumentIssuer
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TenantContext $context,
        private readonly InvoiceSnapshot $snapshot,
    ) {}

    public function type(): string
    {
        return Document::TYPE_INVOICE;
    }

    public function build(OrderView $order): array
    {
        $tenant = $this->context->current();
        $dueDays = (int) $this->settings->get('docs', 'due_days', config('documents.default_due_days'));

        return $this->snapshot->for($order, $tenant, $dueDays);
    }

    public function seriesBase(): string
    {
        return config('documents.invoice_series');
    }

    public function prefix(): string
    {
        return (string) $this->settings->get('docs', 'number_prefix', '');
    }
}
