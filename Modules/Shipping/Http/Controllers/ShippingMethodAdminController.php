<?php

namespace Modules\Shipping\Http\Controllers;

use App\Core\Money\MoneyInput;
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
            // Fills the packeta_hd carrier_id select — fetched on demand
            // right here (screen open), never on a schedule (task 5 brief:
            // partner carriers are few and change rarely). Null on anything
            // that keeps this from working (no key configured yet, the feed
            // unreachable, an unexpected shape) and the form falls back to a
            // plain text field rather than blocking a tenant who already
            // knows their carrier id.
            'packetaCarriers' => $this->partnerCarriers(),
        ]);
    }

    /**
     * Packeta's own partner-carrier catalogue (PPL/DPD/GLS/Česká pošta),
     * resolved by container string rather than a `use` import: shipping
     * declares no manifest `requires` on packeta (module.json), and this
     * admin-only convenience must not become a hard class dependency
     * between the two modules — the same reasoning that has
     * TenantProvisioner resolve Modules\Storefront\Support\DefaultHomepage
     * by string (rozhodnutí 2026-07-26). The try/catch is defense in depth
     * on top of PacketaCarrierCatalog::forTenant()'s own null returns: any
     * failure at all, including one this class never anticipated, must
     * degrade to the text-field fallback, never a 500 on a settings screen.
     *
     * @return list<array{id: string, name: string, country: string, currency: string}>|null
     */
    private function partnerCarriers(): ?array
    {
        try {
            return app('Modules\\Packeta\\Services\\PacketaCarrierCatalog')->forTenant();
        } catch (\Throwable) {
            return null;
        }
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
        $data = [
            'id' => $method->id,
            'provider' => $method->provider,
            'name' => $method->name,
            'description' => $method->description,
            // Korunas: these feed the edit form, which a person types into
            // (wave 3.8). The columns stay in haléře.
            'price' => MoneyInput::toInput($method->price->amount),
            'free_from' => MoneyInput::toInput($method->free_from?->amount),
            'max_weight_g' => $method->max_weight_g,
            'tax_rate_id' => $method->tax_rate_id,
            'is_active' => $method->is_active,
            'position' => $method->position,
        ];

        if (in_array($method->provider(), [ShippingMethod::PROVIDER_PACKETA, ShippingMethod::PROVIDER_PACKETA_HD], true)) {
            // Packeta's settings hold a credential (api_password) and never
            // appear here in the clear — only the non-secret fields, and
            // whether a password is stored at all.
            $data['packeta_api_key'] = $method->packetaApiKey();
            $data['packeta_eshop'] = $method->packetaEshop();
            $data['packeta_default_weight_g'] = $method->packetaDefaultWeightG();
            $data['has_api_password'] = $method->apiPasswordSet();
            // Null for branch pickup — only address delivery names a
            // partner carrier (task 5).
            $data['packeta_carrier_id'] = $method->packetaCarrierId();

            return $data;
        }

        // Pickup address and hours are printed on the storefront: not secret.
        $data['settings'] = $method->settings;

        return $data;
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
            'fee' => MoneyInput::toInput($method->fee->amount),
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
