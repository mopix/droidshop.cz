<?php

namespace App\Http\Controllers\Tenant;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateBillingProfileRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's own billing/invoicing profile (name, IČO, DIČ, address, VAT
 * payer flag) — snapshotted onto issued documents (docs module, spec
 * 2026-07-22 "Snapshot dodavatele"). Core, not a module: every shop needs it
 * regardless of which modules it runs.
 */
class BillingProfileController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function edit(): Response
    {
        $tenant = $this->context->current();

        return Inertia::render('Tenant/BillingProfile', [
            'profile' => [
                'billing_name' => $tenant->billing_name,
                'billing_ico' => $tenant->billing_ico,
                'billing_dic' => $tenant->billing_dic,
                'vat_payer' => (bool) $tenant->vat_payer,
                'billing_address' => $tenant->billing_address ?? ['street' => '', 'city' => '', 'zip' => ''],
            ],
        ]);
    }

    public function update(UpdateBillingProfileRequest $request): RedirectResponse
    {
        $this->context->current()->update($request->validated());

        return back()->with('success', 'Fakturační údaje uloženy.');
    }
}
