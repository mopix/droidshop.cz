<?php

namespace Modules\Shipping\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Shipping\Http\Requests\ReorderRequest;
use Modules\Shipping\Http\Requests\StorePaymentMethodRequest;
use Modules\Shipping\Http\Requests\UpdatePaymentMethodRequest;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Services\PaymentMethodWriter;

/**
 * The shop's payment methods. The listing lives on the shared shipping index;
 * every write here redirects back to it. `{paymentMethod}` binding does the
 * tenant isolation on its own (BelongsToTenant → 404 for a foreign id).
 */
class PaymentMethodAdminController
{
    public function __construct(private readonly PaymentMethodWriter $writer) {}

    public function store(StorePaymentMethodRequest $request): RedirectResponse
    {
        $this->writer->create($request->validated());

        return redirect()
            ->route('admin.shipping.index')
            ->with('success', 'Způsob platby byl vytvořen.');
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $this->writer->update($paymentMethod, $request->validated());

        return back()->with('success', 'Způsob platby byl uložen.');
    }

    public function destroy(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        abort_unless($request->user('web')->can('shipping.manage'), 403);

        $this->writer->delete($paymentMethod);

        return redirect()
            ->route('admin.shipping.index')
            ->with('success', 'Způsob platby byl smazán.');
    }

    public function reorder(ReorderRequest $request): RedirectResponse
    {
        $this->writer->reorder($request->validated('ids'));

        return back()->with('success', 'Pořadí bylo uloženo.');
    }
}
