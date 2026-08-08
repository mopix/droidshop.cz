<?php

namespace App\Core\Tax;

use App\Core\Tenancy\TenantContext;

/**
 * Whether this shop charges VAT (wave 3.7).
 *
 * `tenants.vat_payer` has existed since wave 1.5 and already decided what a
 * document prints and whether a shipping fee needs a rate. It never reached
 * the catalogue, so a shop that is not registered for VAT was made to pick a
 * rate it cannot charge, and its customers were shown "s DPH · bez DPH 826
 * Kč" on a public page — a false statement about someone else's tax status.
 *
 * A service rather than `$tenant->vat_payer` at each call site, for the same
 * reason the page-cache observer is one class and not fifteen writer calls:
 * there are more than ten readers here (the product form, its validation, the
 * variant grid, the product page, the cart, the checkout, the confirmation
 * mail) and the eleventh is the one somebody forgets.
 *
 * No tenant — the platform host, a console command — answers "no". Every
 * caller is rendering something for a shop; without one there is nothing to
 * charge tax on, and throwing would take out shared layouts.
 */
class VatMode
{
    public function __construct(private readonly TenantContext $context) {}

    public function appliesVat(): bool
    {
        return (bool) $this->context->current()?->vat_payer;
    }
}
