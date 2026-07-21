<?php

namespace Modules\Orders\Http\Controllers;

use App\Core\Orders\Exceptions\IllegalTransition;
use Illuminate\Http\RedirectResponse;
use Modules\Orders\Http\Requests\ChangeStateRequest;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Orders\Services\OrderWorkflow;

/**
 * Applies a single state change to one of an order's two independent
 * machines. Authorisation (`orders.edit`) lives in ChangeStateRequest, the
 * same place every other admin write in this codebase puts it (see
 * StoreShippingMethodRequest).
 *
 * `{uuid}` is looked up directly — not via route-model binding — for the
 * same reason OrderAdminController::show() does it that way: uuid, not the
 * numeric id, is the order's public identifier. Order carries
 * BelongsToTenant, so the lookup is tenant-scoped on its own; another shop's
 * uuid resolves to nothing and gets a 404, never a 403 (its existence is not
 * this tenant's business).
 */
class OrderStateController
{
    public function __construct(private readonly OrderWorkflow $workflow) {}

    public function update(ChangeStateRequest $request, string $uuid): RedirectResponse
    {
        $order = Order::query()->where('uuid', $uuid)->first();

        abort_if($order === null, 404);

        $actorId = $request->user('web')->id;
        $to = $request->validated('to');
        $note = $request->validated('note');

        try {
            if ($request->validated('machine') === 'payment') {
                $this->workflow->transitionPayment($order, $to, OrderEvent::ACTOR_ADMIN, $actorId, $note);
            } else {
                $this->workflow->transitionFulfillment($order, $to, OrderEvent::ACTOR_ADMIN, $actorId, $note);
            }
        } catch (IllegalTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Stav objednávky byl změněn.');
    }
}
