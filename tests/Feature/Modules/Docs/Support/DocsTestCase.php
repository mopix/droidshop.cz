<?php

namespace Tests\Feature\Modules\Docs\Support;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Modules\ModuleRegistry;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Shared setup for the docs module's feature tests (wave 1.6 extraction):
 * one tenant with `checkout`/`shipping`/`orders`/`docs` active, and a
 * placePaidOrder() helper that places a real order through checkout and
 * force-settles it paid — the same shape InvoiceIssuerTest/AutoIssueTest/etc.
 * build inline.
 *
 * Unlike those tests, the tenant is left *current* for the whole test (no
 * context->forget()/runAs() dance): DocumentWriterTest resolves DocumentWriter
 * and InvoiceIssuer straight off the container and calls write() directly,
 * matching how a request already inside a tenant's context would.
 */
abstract class DocsTestCase extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    protected Tenant $tenant;

    protected TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create([
            'name' => 'Shop One',
            'billing_name' => 'Shop One s.r.o.',
            'billing_ico' => '12345678',
            'billing_dic' => 'CZ12345678',
            'vat_payer' => true,
            'billing_address' => ['street' => 'Hlavní 1', 'city' => 'Praha', 'zip' => '110 00', 'country' => 'CZ'],
        ]);

        foreach (['checkout', 'shipping', 'orders', 'docs'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->context->set($this->tenant);
    }

    /**
     * Places a real order through checkout, force-settles it paid, and
     * returns its uuid.
     */
    protected function placePaidOrder(): string
    {
        $product = app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'sku' => 'KB-1',
            'price' => 99900,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $shipping = ShippingMethod::query()->create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
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

    /**
     * Places a real order through checkout with a bank-transfer payment
     * method, left unpaid (no force-settle step), and returns its uuid — the
     * shape a proforma is issued against (a payment request only makes sense
     * before payment lands, and QR-eligibility needs a bank account).
     * Mirrors placePaidOrder() otherwise.
     */
    protected function placeUnpaidBankTransferOrder(): string
    {
        $product = app(ProductWriter::class)->create([
            'name' => 'Klávesnice Acme',
            'sku' => 'KB-2',
            'price' => 99900,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'status' => Product::STATUS_ACTIVE,
        ]);

        $shipping = ShippingMethod::query()->create([
            'provider' => ShippingMethod::PROVIDER_FLAT,
            'name' => 'Kurýr',
            'price' => 9900,
            'currency' => 'CZK',
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'is_active' => true,
        ]);

        $payment = PaymentMethod::query()->create([
            'provider' => PaymentMethod::PROVIDER_BANK_TRANSFER,
            'name' => 'Bankovní převod',
            'fee' => 0,
            'currency' => 'CZK',
            'tax_rate_id' => app(TaxRates::class)->default()->id,
            'is_active' => true,
            'settings' => ['account' => 'CZ6508000000192000145399'],
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

        return Order::query()->where('uuid', $placed->uuid())->firstOrFail()->uuid;
    }

    /**
     * Deactivates the docs module for the test tenant, so ShopModules->has('docs')
     * is false for the remainder of the test.
     */
    protected function disableDocsModule(): void
    {
        app(ModuleRegistry::class)->deactivate($this->tenant, 'docs');
    }

    /**
     * Places a paid order and issues its invoice through the same kernel
     * contract a real request would use, then re-fetches the persisted
     * Document row (issue() returns a DocumentView, not necessarily the
     * concrete model callers of this helper want to assert against).
     */
    protected function issuedInvoice(): Document
    {
        $orderUuid = $this->placePaidOrder();

        app(DocumentIssuer::class)->issue($orderUuid, Document::TYPE_INVOICE);

        return Document::query()
            ->where('type', Document::TYPE_INVOICE)
            ->firstOrFail();
    }

    /**
     * Places a paid order, issues its invoice, and returns the Order model
     * (not just the uuid) so credit-note gate tests can flip fulfillment or
     * payment status afterwards.
     */
    protected function issuedInvoiceOrder(): Order
    {
        $orderUuid = $this->placePaidOrder();

        app(DocumentIssuer::class)->issue($orderUuid, Document::TYPE_INVOICE);

        return Order::query()->where('uuid', $orderUuid)->firstOrFail();
    }

    /**
     * Places a paid order, marks it cancelled, but issues no invoice — the
     * "reversed but nothing to correct" case the gate must still reject.
     */
    protected function cancelledOrderWithoutInvoice(): Order
    {
        $orderUuid = $this->placePaidOrder();

        $order = Order::query()->where('uuid', $orderUuid)->firstOrFail();
        $order->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);

        return $order;
    }
}
