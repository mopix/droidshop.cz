<?php

namespace Modules\Orders\Http\Controllers;

use App\Core\Mail\Contracts\MailService;
use App\Core\Mail\MailKind;
use App\Core\Orders\Exceptions\IllegalTransition;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Modules\Orders\Http\Requests\ChangeStateRequest;
use Modules\Orders\Mail\OrderStateChanged;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Orders\Services\OrderWorkflow;

/**
 * Applies a single state change to one of an order's two independent
 * machines. Authorisation (`orders.edit`) lives in ChangeStateRequest, the
 * same place every other admin write in this codebase puts it (see
 * StoreShippingMethodRequest). Cancellation is deliberately not reachable
 * through here (see ChangeStateRequest's own note) — it has its own
 * endpoint, permission and confirm dialog (OrderEditController::cancel).
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
    private const FULFILLMENT_LABELS = [
        Order::FULFILLMENT_NEW => 'Nová',
        Order::FULFILLMENT_ACCEPTED => 'Přijatá',
        Order::FULFILLMENT_PROCESSING => 'Zpracovává se',
        Order::FULFILLMENT_SHIPPED => 'Odeslaná',
        Order::FULFILLMENT_DELIVERED => 'Doručená',
        Order::FULFILLMENT_CANCELLED => 'Zrušená',
    ];

    private const PAYMENT_LABELS = [
        Order::PAYMENT_UNPAID => 'Nezaplaceno',
        Order::PAYMENT_PAID => 'Zaplaceno',
        Order::PAYMENT_REFUNDED => 'Vráceno',
    ];

    public function __construct(
        private readonly OrderWorkflow $workflow,
        private readonly MailService $mail,
        private readonly TenantContext $context,
    ) {}

    public function update(ChangeStateRequest $request, string $uuid): RedirectResponse
    {
        $order = Order::query()->where('uuid', $uuid)->first();

        abort_if($order === null, 404);

        $actorId = $request->user('web')->id;
        $machine = $request->validated('machine');
        $to = $request->validated('to');
        $note = $request->validated('note');

        try {
            if ($machine === 'payment') {
                $this->workflow->transitionPayment($order, $to, OrderEvent::ACTOR_ADMIN, $actorId, $note);
            } else {
                $this->workflow->transitionFulfillment($order, $to, OrderEvent::ACTOR_ADMIN, $actorId, $note);
            }
        } catch (IllegalTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        // Sent only when the admin ticked the box on this particular
        // change — never automatically, and only reached once the workflow
        // call above has returned (its own DB::transaction has already
        // committed by then), so a mail can never survive a transition that
        // did not actually happen.
        if ($request->boolean('send_email')) {
            $this->sendStateChangedMail($order, $machine, $to, $note);
        }

        return back()->with('success', 'Stav objednávky byl změněn.');
    }

    private function sendStateChangedMail(Order $order, string $machine, string $to, ?string $note): void
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            return;
        }

        $label = $machine === 'payment'
            ? (self::PAYMENT_LABELS[$to] ?? $to)
            : (self::FULFILLMENT_LABELS[$to] ?? $to);

        $this->mail->send(
            new OrderStateChanged($tenant->name, $order->number, $label, $note),
            $order->email,
            MailKind::Transactional,
            $tenant,
        );
    }
}
