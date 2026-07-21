<?php

namespace Modules\Shipping\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Response;
use Modules\Shipping\Http\Requests\UpdateMatrixRequest;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;

/**
 * The grid that says which payment is allowed with which shipping. A shipping
 * row with no boxes ticked allows every active payment (plan decision 1), so an
 * untouched screen never traps a shop into taking no orders.
 */
class ShippingMatrixAdminController
{
    public function show(Request $request): Response
    {
        abort_unless($request->user('web')->can('shipping.manage'), 403);

        $shippingMethods = ShippingMethod::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->get();

        $paymentMethods = PaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->get();

        return inertia('Modules/Shipping/Matrix', [
            'shippingMethods' => $shippingMethods
                ->map(fn (ShippingMethod $m) => ['id' => $m->id, 'name' => $m->name])
                ->all(),
            'paymentMethods' => $paymentMethods
                ->map(fn (PaymentMethod $m) => ['id' => $m->id, 'name' => $m->name])
                ->all(),
            // Current pairs, keyed by shipping method id → list of payment ids.
            'matrix' => $shippingMethods->mapWithKeys(fn (ShippingMethod $m) => [
                $m->id => $m->paymentMethods()->pluck('payment_methods.id')->all(),
            ])->all(),
        ]);
    }

    public function update(UpdateMatrixRequest $request): RedirectResponse
    {
        /** @var array<int|string, list<int>> $submitted */
        $submitted = $request->validated('matrix', []);

        // Resolve both axes through the tenant-scoped models. Only these ids may
        // end up in the pivot; a shipping or payment id from another shop is
        // never iterated and never written.
        $shippingMethods = ShippingMethod::query()->get();
        $paymentIds = PaymentMethod::query()->pluck('id')->all();

        DB::transaction(function () use ($submitted, $shippingMethods, $paymentIds) {
            foreach ($shippingMethods as $shipping) {
                $wanted = array_values(array_intersect(
                    array_map('intval', $submitted[$shipping->id] ?? []),
                    $paymentIds,
                ));

                // sync() removes the rows no longer ticked and inserts the new
                // ones; an empty set clears the row entirely, which the contract
                // reads as "all active payments allowed".
                $shipping->paymentMethods()->sync(
                    collect($wanted)
                        ->mapWithKeys(fn (int $id) => [$id => ['tenant_id' => $shipping->tenant_id]])
                        ->all()
                );
            }
        });

        return back()->with('success', 'Matice dopravy a plateb byla uložena.');
    }
}
