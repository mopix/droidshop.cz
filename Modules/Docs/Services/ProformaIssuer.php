<?php

namespace Modules\Docs\Services;

use App\Core\Orders\Contracts\OrderView;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use Modules\Docs\Models\Document;
use Modules\Docs\Services\Contracts\TypedDocumentIssuer;

/**
 * The proforma type's rule (spec §16.6). No gate beyond the module being on:
 * issuing a payment request for any order is legitimate — unlike a credit
 * note, a proforma corrects nothing and requires no prior invoice.
 */
class ProformaIssuer implements TypedDocumentIssuer
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TenantContext $context,
        private readonly ProformaSnapshot $snapshot,
    ) {}

    public function type(): string
    {
        return Document::TYPE_PROFORMA;
    }

    public function build(OrderView $order): array
    {
        $tenant = $this->context->current();
        $dueDays = (int) $this->settings->get('docs', 'due_days', config('documents.default_due_days'));

        return $this->snapshot->for($order, $tenant, $dueDays);
    }

    public function seriesBase(): string
    {
        return config('documents.proforma_series');
    }

    public function prefix(): string
    {
        return (string) $this->settings->get('docs', 'proforma_prefix', '');
    }
}
