<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Tax\TaxRates;
use Modules\Checkout\Models\Cart;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * A document must be readable on its own: the lines it prints have to add up to
 * the amount it asks for. Until wave 2.12 shipping and the payment fee lived
 * only in the total and the VAT recap, so a customer's invoice showed 1 998 Kč
 * of lines under a 2 097 Kč total with nothing explaining the difference.
 */
class InvoiceSnapshotTest extends DocsTestCase
{
    /**
     * Named differently from the parent's issuedInvoice(): that one is
     * declared protected on DocsTestCase, and PHP forbids a subclass from
     * redeclaring an inherited method with a narrower (private) visibility —
     * doing so is a fatal compile error, not a runtime one.
     */
    private function freshInvoice(): Document
    {
        app(DocumentIssuer::class)->issue($this->placePaidOrder(), Document::TYPE_INVOICE);

        return Document::query()->where('type', Document::TYPE_INVOICE)->latest('id')->firstOrFail();
    }

    public function test_the_lines_add_up_to_the_documents_total(): void
    {
        $invoice = $this->freshInvoice();

        $lines = array_sum(array_map(
            static fn (array $item): int => (int) $item['line_total'],
            $invoice->items,
        ));

        $this->assertSame($invoice->total->amount, $lines, 'Součet řádků se musí rovnat částce k úhradě.');
    }

    public function test_shipping_and_the_payment_fee_are_named_lines(): void
    {
        $invoice = $this->freshInvoice();
        $names = array_column($invoice->items, 'name');

        $this->assertTrue(
            collect($names)->contains(fn (string $n) => str_contains($n, 'Doprava')),
            'Doklad musí pojmenovat dopravu: '.implode(' | ', $names)
        );
        $this->assertTrue(
            collect($names)->contains(fn (string $n) => str_contains($n, 'Platba')),
            'Doklad musí pojmenovat způsob platby: '.implode(' | ', $names)
        );
    }

    public function test_a_zero_priced_shipping_still_gets_a_line(): void
    {
        // Owner's decision 2026-07-31: a chosen delivery is shown even at 0 Kč,
        // so the customer can see what they picked.
        //
        // Deviation from the brief's literal test body: DocsTestCase::placePaidOrder()
        // always CREATEs a brand new ShippingMethod row with a hardcoded 9900
        // price, so an UPDATE issued beforehand (as the brief's snippet had it)
        // runs against an empty table and is a silent no-op — the order still
        // gets placed with the 9900 row created afterwards, and `charged` (set
        // once, at placement, per wave 2.12's own immutable-snapshot rule)
        // stays 9900. Placing the order against a shipping method that is
        // actually free at placement time is the only way to observe this.
        app(DocumentIssuer::class)->issue($this->placeOrderWithFreeShipping(), Document::TYPE_INVOICE);
        $invoice = Document::query()->where('type', Document::TYPE_INVOICE)->latest('id')->firstOrFail();
        $shipping = collect($invoice->items)->first(fn (array $i) => str_contains($i['name'], 'Doprava'));

        $this->assertNotNull($shipping);
        $this->assertSame(0, (int) $shipping['line_total']);
    }

    public function test_the_shipping_line_carries_its_own_vat_rate(): void
    {
        $invoice = $this->freshInvoice();
        $shipping = collect($invoice->items)->first(fn (array $i) => str_contains($i['name'], 'Doprava'));

        $this->assertNotNull($shipping);
        $this->assertNotSame('', (string) $shipping['tax_rate'], 'Sazba DPH dopravy musí být na řádku.');
    }

    public function test_a_tenant_with_no_country_falls_back_to_cz(): void
    {
        // DocsTestCase's own tenant always sets 'country' => 'CZ' — reproduce
        // the pre-2.12b shape (or a profile the owner never opened) by
        // stripping the key rather than adding a second tenant.
        $this->tenant->update([
            'billing_address' => ['street' => 'Hlavní 1', 'city' => 'Praha', 'zip' => '110 00'],
        ]);

        $invoice = $this->freshInvoice();

        $this->assertSame('CZ', $invoice->supplier['address']['country'] ?? null);
    }

    public function test_a_tenant_with_an_explicit_country_keeps_it(): void
    {
        $this->tenant->update([
            'billing_address' => ['street' => 'Hlavná 1', 'city' => 'Bratislava', 'zip' => '811 01', 'country' => 'SK'],
        ]);

        $invoice = $this->freshInvoice();

        $this->assertSame('SK', $invoice->supplier['address']['country'] ?? null);
    }

    public function test_a_credit_note_negates_the_new_lines_too(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        Order::query()->where('uuid', $uuid)->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_CREDIT_NOTE);

        $note = Document::query()->where('type', Document::TYPE_CREDIT_NOTE)->latest('id')->firstOrFail();
        $shipping = collect($note->items)->first(fn (array $i) => str_contains($i['name'], 'Doprava'));

        $this->assertNotNull($shipping);
        $this->assertLessThanOrEqual(0, (int) $shipping['line_total'], 'Dobropis musí mít i dopravu se záporným znaménkem.');
    }

    /**
     * Same shape as DocsTestCase::placePaidOrder(), except the shipping
     * method is free — needed because that helper hardcodes a 9900 price and
     * `charged` is fixed at placement, not recomputed later.
     */
    private function placeOrderWithFreeShipping(): string
    {
        $product = app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'sku' => 'KB-FREE-SHIP',
            'price' => 99900,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $shipping = ShippingMethod::query()->create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr zdarma',
            'price' => 0,
            'currency' => 'CZK',
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'is_active' => true,
        ]);

        $payment = PaymentMethod::query()->create([
            'provider' => PaymentMethod::PROVIDER_COD,
            'name' => 'Dobírka',
            'fee' => 0,
            'currency' => 'CZK',
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'is_active' => true,
        ]);

        /** @var Cart $cart */
        $cart = app(CartRepository::class)->forToken(null);
        app(CartRepository::class)->addItem($cart, $product->id, 2);

        $placed = app(OrderPlacement::class)->place(new PlacementRequest(
            cart: $cart,
            shippingMethodId: $shipping->id,
            paymentMethodId: $payment->id,
            email: 'jana@example.cz',
            phone: '+420777123456',
            billing: [
                'name' => 'Jana Nováková',
                'street' => 'Hlavní 1',
                'city' => 'Praha',
                'zip' => '110 00',
                'country' => 'CZ',
            ],
            shipping: null,
            checkoutToken: 'tok-'.bin2hex(random_bytes(8)),
            customerId: null,
            source: 'storefront',
            note: null,
        ));

        $order = Order::query()->where('uuid', $placed->uuid())->firstOrFail();
        $order->forceFill(['payment_status' => Order::PAYMENT_PAID])->save();

        return $order->uuid;
    }
}
