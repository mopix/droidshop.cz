<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Mail\MailKind;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Settings\SettingsService;
use App\Core\Storage\FileStorage;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\MailMessage;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Checkout\Models\Cart;
use Modules\Docs\Mail\InvoiceIssued;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Wave 1.5 Task 6 — e-mailing the issued invoice PDF to the customer.
 *
 * QUEUE_CONNECTION=sync in phpunit.xml, so InvoiceIssuer::issue()'s
 * GenerateInvoicePdf::dispatch() runs inline in these tests, and MailService
 * (QueuedMailService) dispatches SendTenantMail inline too — both land on
 * Mail::fake() the same way PlaceOrderTest's confirmation-email test does.
 */
class InvoiceEmailTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

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
    }

    // --- helpers ------------------------------------------------------

    private function placePaidCodOrder(): Order
    {
        return $this->context->runAs($this->tenant, function (): Order {
            $order = $this->placeOrder();
            $order->forceFill(['payment_status' => Order::PAYMENT_PAID])->save();

            return $order;
        });
    }

    private function placeOrder(): Order
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
            'settings' => [],
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

        return Order::query()->where('uuid', $placed->uuid())->firstOrFail();
    }

    private function issue(string $uuid): Document
    {
        /** @var Document $document */
        $document = $this->context->runAs($this->tenant, fn () => app(DocumentIssuer::class)->issue($uuid));

        return $document;
    }

    // --- scenarios ------------------------------------------------------

    public function test_invoice_email_sent_after_pdf_when_enabled(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);
        Mail::fake();

        $this->context->runAs($this->tenant, fn () => app(SettingsService::class)->set('docs', 'email_invoice', true));

        $order = $this->placePaidCodOrder();
        $issued = $this->issue($order->uuid);

        Mail::assertSent(InvoiceIssued::class, function (InvoiceIssued $mail) use ($issued) {
            return $mail->invoiceNumber === $issued->number
                && $mail->hasTo('jana@example.cz');
        });

        $sentAt = $this->context->runAs($this->tenant, fn () => $issued->fresh()->sent_at);
        $this->assertNotNull($sentAt);

        // Logged as transactional against the tenant — never blocked by an
        // exhausted quota (product decision, MailKind).
        $message = MailMessage::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame(MailKind::Transactional, $message->kind);
    }

    public function test_no_email_when_disabled(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);
        Mail::fake();

        $this->context->runAs($this->tenant, fn () => app(SettingsService::class)->set('docs', 'email_invoice', false));

        $order = $this->placePaidCodOrder();
        $issued = $this->issue($order->uuid);

        Mail::assertNotSent(InvoiceIssued::class);

        $sentAt = $this->context->runAs($this->tenant, fn () => $issued->fresh()->sent_at);
        $this->assertNull($sentAt);
    }
}
