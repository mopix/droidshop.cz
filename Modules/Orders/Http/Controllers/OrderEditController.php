<?php

namespace Modules\Orders\Http\Controllers;

use App\Core\Catalog\Contracts\CatalogProduct;
use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Orders\Exceptions\IllegalTransition;
use App\Core\Orders\Exceptions\OrderEditingClosed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Orders\Http\Requests\CancelOrderRequest;
use Modules\Orders\Http\Requests\StoreManualOrderRequest;
use Modules\Orders\Http\Requests\UpdateOrderRequest;
use Modules\Orders\Models\Order;
use Modules\Orders\Models\OrderEvent;
use Modules\Orders\Services\OrderEditor;

/**
 * The admin write side of an order: editing lines/addresses, creating a
 * manual order, and cancellation (storno).
 *
 * `{uuid}` is looked up directly, the same way OrderStateController does it —
 * Order's BelongsToTenant global scope makes a foreign tenant's uuid resolve
 * to nothing and 404, never a 403 (its existence is not this tenant's
 * business).
 */
class OrderEditController
{
    public function __construct(
        private readonly OrderEditor $editor,
        private readonly ProductCatalog $catalog,
    ) {}

    public function create(Request $request): Response
    {
        abort_unless($request->user('web')->can('orders.edit'), 403);

        return inertia('Modules/Orders/Create', [
            'products' => $this->productOptions(),
        ]);
    }

    public function store(StoreManualOrderRequest $request): RedirectResponse
    {
        $order = $this->editor->createManual(
            lines: $request->validated('items'),
            billing: $request->validated('billing'),
            shipping: $request->validated('shipping'),
            email: $request->validated('email'),
            phone: $request->validated('phone'),
            shippingMethodId: $request->validated('shipping_method_id'),
            paymentMethodId: $request->validated('payment_method_id'),
            note: $request->validated('note'),
            actorId: $request->user('web')->id,
        );

        return redirect()
            ->route('admin.orders.show', $order->uuid)
            ->with('success', 'Objednávka byla vytvořena.');
    }

    public function update(UpdateOrderRequest $request, string $uuid): RedirectResponse
    {
        $order = Order::query()->where('uuid', $uuid)->first();

        abort_if($order === null, 404);

        try {
            $this->editor->edit(
                order: $order,
                lines: $request->validated('items'),
                billing: $request->validated('billing'),
                shipping: $request->validated('shipping'),
                email: $request->validated('email'),
                phone: $request->validated('phone'),
                note: $request->validated('note'),
                actorType: OrderEvent::ACTOR_ADMIN,
                actorId: $request->user('web')->id,
            );
        } catch (OrderEditingClosed $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Objednávka byla upravena.');
    }

    public function cancel(CancelOrderRequest $request, string $uuid): RedirectResponse
    {
        $order = Order::query()->where('uuid', $uuid)->first();

        abort_if($order === null, 404);

        try {
            $this->editor->cancel(
                order: $order,
                reason: $request->validated('reason'),
                returnStock: $request->boolean('return_stock'),
                sendEmail: $request->boolean('send_email'),
                actorType: OrderEvent::ACTOR_ADMIN,
                actorId: $request->user('web')->id,
            );
        } catch (IllegalTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Objednávka byla stornována.');
    }

    /**
     * A picker list for the manual-order form. Goes through the kernel's
     * ProductCatalog contract, never the products module's Eloquent model
     * directly — the same boundary OrderPlacer/OrderEditor keep — so this
     * controller does not know or care whether products is even active
     * (an inactive/off module's null binding simply returns nothing to
     * pick from, and the form is not broken by it, just empty).
     *
     * @return list<array{id: int, name: string, sku: ?string, price: int, currency: string}>
     */
    private function productOptions(): array
    {
        return $this->catalog->search('', 200)
            ->map(fn (CatalogProduct $product): array => [
                'id' => $product->getKey(),
                'name' => $product->catalogName(),
                'sku' => $product->catalogSku(),
                'price' => $product->catalogPrice()->amount,
                'currency' => $product->catalogPrice()->currency,
            ])
            ->values()
            ->all();
    }
}
