<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Tax\TaxRates;
use Modules\Checkout\Models\Cart;
use Modules\Discounts\Models\Discount;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

class CreditNoteIssuerTest extends DocsTestCase
{
    public function test_credit_note_is_idempotent_and_uses_its_own_series(): void
    {
        $order = $this->issuedInvoiceOrder();
        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        $issuer = $this->app->make(DocumentIssuer::class);

        $first = $issuer->issue($order->uuid, Document::TYPE_CREDIT_NOTE);
        $second = $issuer->issue($order->uuid, Document::TYPE_CREDIT_NOTE);

        $this->assertSame($first->documentNumber(), $second->documentNumber());
        $this->assertSame(1, Document::query()->where('type', 'credit_note')->count());

        $row = Document::query()->where('type', 'credit_note')->first();
        $this->assertStringContainsString(':', $row->series); // credit_notes:{year}
        $this->assertStringStartsWith('credit_notes:', $row->series);
    }

    /**
     * A credit note negates whatever the invoice actually charged. Since the
     * wave's binding decision keeps the discount out of the money entirely
     * (line items already carry discounted amounts, the note is
     * informational-only — rozhodnutí 2026-07-28), CreditNoteSnapshot never
     * has to know discounts exist: negating the already-reduced invoice total
     * is automatically correct, with no special branch for a discounted
     * order.
     */
    public function test_a_credit_note_for_a_discounted_order_negates_the_already_reduced_total(): void
    {
        $this->activateModule($this->tenant, 'discounts');

        $product = app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'sku' => 'KB-DISC',
            'price' => 100000,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

        $cart = Cart::query()->create([
            'token' => 'tok-'.bin2hex(random_bytes(6)),
            'coupon_code' => 'SLEVA10',
        ]);
        $cart->items()->create([
            'product_id' => $product->id,
            'variant_id' => 0,
            'quantity' => 1,
            'unit_price' => $product->price,
            'currency' => 'CZK',
        ]);

        $placed = app(OrderPlacement::class)->place(new PlacementRequest(
            cart: $cart->fresh(),
            shippingMethodId: null,
            paymentMethodId: null,
            email: 'kupujici@example.com',
            phone: null,
            billing: [
                'name' => 'Jan Novák',
                'street' => 'Dlouhá 1',
                'city' => 'Praha',
                'zip' => '110 00',
                'country' => 'CZ',
            ],
            shipping: null,
            checkoutToken: 'chk-'.bin2hex(random_bytes(6)),
        ));

        $order = Order::query()->where('uuid', $placed->uuid())->firstOrFail();
        $this->assertSame(90000, $order->total->amount);

        $issuer = $this->app->make(DocumentIssuer::class);
        $issuer->issue($order->uuid, Document::TYPE_INVOICE);

        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);

        $creditNote = $issuer->issue($order->uuid, Document::TYPE_CREDIT_NOTE);

        $this->assertSame(-90000, $creditNote->documentTotal()->amount);
    }
}
