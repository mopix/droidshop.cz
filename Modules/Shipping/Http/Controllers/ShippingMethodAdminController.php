<?php

namespace Modules\Shipping\Http\Controllers;

use App\Core\Tax\TaxRates;
use App\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Shipping\Http\Requests\ReorderRequest;
use Modules\Shipping\Http\Requests\StoreShippingMethodRequest;
use Modules\Shipping\Http\Requests\UpdateShippingMethodRequest;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Services\ShippingMethodWriter;

/**
 * The shop's shipping methods, and — read-only here — the payment methods that
 * sit next to them on the same screen. Payment CRUD is its own controller;
 * this one owns the shared index so both lists render in one place.
 *
 * `{shippingMethod}` route-model binding does the tenant isolation on its own:
 * ShippingMethod carries BelongsToTenant, so another shop's id never resolves
 * and Laravel answers 404 before the controller runs.
 */
class ShippingMethodAdminController
{
    public function __construct(
        private readonly ShippingMethodWriter $writer,
        private readonly TaxRates $rates,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user('web')->can('shipping.manage'), 403);

        return inertia('Modules/Shipping/Index', [
            'shippingMethods' => ShippingMethod::query()
                ->orderBy('position')
                ->get()
                ->map($this->presentShipping(...))
                ->all(),
            'paymentMethods' => PaymentMethod::query()
                ->orderBy('position')
                ->get()
                ->map($this->presentPayment(...))
                ->all(),
            'taxRates' => $this->rates->all()->values()->map(fn (TaxRate $rate) => [
                'id' => $rate->id,
                'name' => $rate->name,
                'percent' => $rate->percent(),
            ]),
        ]);
    }

    public function store(StoreShippingMethodRequest $request): RedirectResponse
    {
        $this->writer->create($request->validated());

        return redirect()
            ->route('admin.shipping.index')
            ->with('success', 'Způsob dopravy byl vytvořen.');
    }

    public function update(UpdateShippingMethodRequest $request, ShippingMethod $shippingMethod): RedirectResponse
    {
        $this->writer->update($shippingMethod, $request->validated());

        return back()->with('success', 'Způsob dopravy byl uložen.');
    }

    public function destroy(Request $request, ShippingMethod $shippingMethod): RedirectResponse
    {
        abort_unless($request->user('web')->can('shipping.manage'), 403);

        $this->writer->delete($shippingMethod);

        return redirect()
            ->route('admin.shipping.index')
            ->with('success', 'Způsob dopravy byl smazán.');
    }

    public function reorder(ReorderRequest $request): RedirectResponse
    {
        $this->writer->reorder($request->validated('ids'));

        return back()->with('success', 'Pořadí bylo uloženo.');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentShipping(ShippingMethod $method): array
    {
        return [
            'id' => $method->id,
            'provider' => $method->provider,
            'name' => $method->name,
            'description' => $method->description,
            'price' => $method->price->amount,
            'free_from' => $method->free_from?->amount,
            'max_weight_g' => $method->max_weight_g,
            'tax_rate_id' => $method->tax_rate_id,
            'is_active' => $method->is_active,
            'position' => $method->position,
            // Pickup address and hours are printed on the storefront: not secret.
            'settings' => $method->settings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPayment(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'provider' => $method->provider,
            'name' => $method->name,
            'description' => $method->description,
            'fee' => $method->fee->amount,
            'tax_rate_id' => $method->tax_rate_id,
            'is_active' => $method->is_active,
            'position' => $method->position,
            // The account never leaves the server in the clear — only the
            // masked tail and whether one is set at all.
            'account_masked' => $method->maskedAccount(),
            'account_set' => $method->accountSet(),
            // Comgate: merchant and test flag are shown; the secret never
            // leaves the server, only whether one is stored.
            'comgate_merchant' => $method->comgateMerchant(),
            'comgate_test' => $method->comgateTest(),
            'secret_set' => $method->secretSet(),
        ];
    }
}
