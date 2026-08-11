<?php

namespace App\Core\Orders\Contracts;

use App\Core\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a caller outside the orders module may rely on about a placed order.
 *
 * Deliberately narrow, matching App\Core\Checkout\Contracts\CartShape: enough
 * for a customer's "my orders" page or an admin listing to render an order,
 * without tying the kernel to the Eloquent model behind it. Every accessor is
 * prefixed `order` rather than named after the column it reads —
 * `orderItems()` in particular must not collide with
 * Modules\Orders\Models\Order::items(), the Eloquent relation the module
 * itself uses to write line items.
 *
 * `Modules\Orders\Models\Order` implements this.
 */
interface OrderView
{
    /**
     * The order's internal primary key, for a caller that has to key a foreign
     * row against it — an issued document's order_id, say. Deliberately not the
     * uuid: the uuid is the public identifier, this is the storage identity.
     */
    public function orderInternalId(): int;

    public function orderUuid(): string;

    public function orderNumber(): string;

    public function orderCustomerId(): ?int;

    public function orderEmail(): string;

    public function orderPhone(): ?string;

    public function orderFulfillmentStatus(): string;

    public function orderPaymentStatus(): string;

    /**
     * The gateway's transaction reference stored at payment initiation, or
     * null for an order that never went to an online gateway. The payment
     * return and webhook look up the order by uuid and re-verify THIS stored
     * reference — never a reference from the request — so a paid transaction
     * cannot be pointed at someone else's order.
     */
    public function orderPaymentReference(): ?string;

    public function orderItemsTotal(): Money;

    public function orderShippingTotal(): Money;

    public function orderTotal(): Money;

    public function orderCurrency(): string;

    /**
     * When the order was actually placed, or null for one still being
     * assembled (should not normally be reachable through this contract, but
     * a caller must not have to guess).
     */
    public function orderPlacedAt(): ?Carbon;

    /**
     * The order's line items, freshly read — never a cached relation, so a
     * caller never has to remember to refresh() after a mutation.
     *
     * @return Collection<int, mixed>
     */
    public function orderItems(): Collection;

    /**
     * The chosen payment method's snapshot as it was recorded at placement —
     * id, name, fee, tax rate, currency. Never a credential: the account
     * behind a bank-transfer QR is deliberately not stored here (spec §16.5),
     * so a confirmation page reads the id from this snapshot and re-resolves
     * the live payment method to build its payment instruction. Null when no
     * payment method was chosen (the free personal-pickup fallback).
     *
     * @return array<string, mixed>|null
     */
    public function orderPaymentSnapshot(): ?array;

    /**
     * The chosen shipping method's snapshot as it was recorded at placement —
     * id, name, price charged, tax rate, currency, and, for a carrier that
     * delivers to a branch, a nested `pickup_point` (code, name, address,
     * the carrier's provider key, and the order's total weight in grams —
     * see Modules\Orders\Services\OrderPlacer::resolvePickupPoint()). Null
     * when no shipping method was chosen.
     *
     * @return array<string, mixed>|null
     */
    public function orderShippingSnapshot(): ?array;

    /**
     * The billing party as it was recorded at placement — name, address, and
     * (when the buyer is a company) ico/dic. A snapshot, never re-read from a
     * customer profile that may have changed since.
     *
     * @return array<string, mixed>
     */
    public function orderBilling(): array;

    /**
     * The delivery address as it was recorded at placement, or null when the
     * shopper ships to the same address they're billed at — the same
     * fallback the admin order page already shows ("shodná s fakturační")
     * for a null orders.shipping. A caller that needs an address regardless
     * of whether one was chosen must apply that fallback itself, e.g.
     * `orderShippingAddress() ?? orderBilling()` (Modules\Packeta\Services\
     * ShipmentSubmitter, home-delivery wave) — this accessor stays a plain
     * mirror of the column rather than baking the fallback in, the same
     * separation orderShippingSnapshot() keeps from orderBilling() itself.
     *
     * @return array<string, mixed>|null
     */
    public function orderShippingAddress(): ?array;

    /**
     * The per-rate VAT recap computed in haléře at placement (base, vat, rate).
     * A document takes its VAT from here rather than recomputing it, so the
     * money on the invoice is exactly the money the customer paid.
     *
     * @return array<int, array<string, mixed>>
     */
    public function orderVatSummary(): array;

    /**
     * What came off the order's items as a whole (orders.discount_total).
     * Never an input to any total — the lines already carry the reduction
     * (order_items.line_total is net of it) — a caller reads this only to
     * report the discount, e.g. a document's informational note. Read live
     * rather than from a cached snapshot: an order edited after a discount
     * fired can end up with a smaller (or zero) live total than the discount
     * rows it still carries (Modules\Orders\Services\OrderEditor preserves
     * each surviving line's own share but never touches order_discounts), so
     * a caller that wants money-that-still-applies must come here, not to
     * orderDiscounts() below.
     */
    public function orderDiscountTotal(): Money;

    /**
     * The discounts that fired on this order, as they were recorded at
     * placement (order_discounts) — for naming a discount (code, name, type),
     * never for totalling it: see orderDiscountTotal()'s docblock for why
     * these rows can disagree with the order's live discount total after an
     * edit. Freshly read, never a cached relation, matching orderItems().
     *
     * @return Collection<int, mixed>
     */
    public function orderDiscounts(): Collection;
}
