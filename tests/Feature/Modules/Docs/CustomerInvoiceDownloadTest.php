<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Documents\Contracts\DocumentIssuer;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\PlacementRequest;
use App\Core\Storage\FileStorage;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\Checkout\Models\Cart;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\Concerns\ActsAsCustomer;
use Tests\TestCase;

/**
 * Wave 1.5 Task 8 — a signed-in customer downloading their own invoice from
 * the storefront account. Mirrors DocumentAdminTest's download coverage, but
 * gated on auth:customer + OrderBook::findForCustomer() ownership instead of
 * the tenant.member admin gate.
 */
class CustomerInvoiceDownloadTest extends TestCase
{
    use ActivatesModules;
    use ActsAsCustomer;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
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

        foreach (['storefront', 'customers', 'checkout', 'shipping', 'orders', 'docs'] as $module) {
            $this->activateModule($this->tenant, $module);
        }
    }

    private function url(string $path): string
    {
        return 'http://shop1.droidshop'.$path;
    }

    /**
     * Places and pays an order for the given customer, then issues its
     * invoice, returning the document's public number.
     */
    private function issueInvoiceFor(Tenant $tenant, ?int $customerId): string
    {
        return $this->context->runAs($tenant, function () use ($customerId): string {
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
            app(CartRepository::class)->addItem($cart, $product->id, 1);

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
                customerId: $customerId,
                source: 'storefront',
                note: null,
            ));

            $order = Order::query()->where('uuid', $placed->uuid())->firstOrFail();
            $order->forceFill(['payment_status' => Order::PAYMENT_PAID])->save();

            return app(DocumentIssuer::class)->issue($order->uuid)->documentNumber();
        });
    }

    public function test_the_owning_customer_can_download_their_invoice(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $customer = $this->makeCustomer($this->tenant);
        $number = $this->issueInvoiceFor($this->tenant, $customer->id);

        $response = $this->actingAsCustomer($customer)->get($this->url('/faktura/'.$number.'/pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Robots-Tag', 'noindex');
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    public function test_a_different_authenticated_customer_cannot_download_it(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $owner = $this->makeCustomer($this->tenant);
        $stranger = $this->makeCustomer($this->tenant);
        $number = $this->issueInvoiceFor($this->tenant, $owner->id);

        $response = $this->actingAsCustomer($stranger)->get($this->url('/faktura/'.$number.'/pdf'));

        $response->assertNotFound();
    }

    public function test_a_guest_is_redirected_to_the_customer_login(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $owner = $this->makeCustomer($this->tenant);
        $number = $this->issueInvoiceFor($this->tenant, $owner->id);

        $response = $this->call('GET', $this->url('/faktura/'.$number.'/pdf'));

        $response->assertRedirect($this->url('/prihlaseni'));
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_a_foreign_tenants_document_number_is_not_found(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create([
            'billing_name' => 'Shop Two s.r.o.',
            'billing_address' => ['street' => 'Vedlejší 2', 'city' => 'Brno', 'zip' => '602 00', 'country' => 'CZ'],
        ]);
        foreach (['storefront', 'customers', 'checkout', 'shipping', 'orders', 'docs'] as $module) {
            $this->activateModule($other, $module);
        }

        $foreignOwner = $this->makeCustomer($other);
        $number = $this->issueInvoiceFor($other, $foreignOwner->id);

        // Requested against shop1's host, where a document with this number
        // (foreign tenant_id) is invisible to Document's BelongsToTenant scope.
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->actingAsCustomer($customer)->get($this->url('/faktura/'.$number.'/pdf'));

        $response->assertNotFound();
    }

    public function test_a_nonexistent_document_number_is_not_found(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->actingAsCustomer($customer)->get($this->url('/faktura/does-not-exist/pdf'));

        $response->assertNotFound();
    }

    // --- account order detail link -------------------------------------

    public function test_the_order_detail_page_links_to_the_invoice_once_issued(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $customer = $this->makeCustomer($this->tenant);
        $number = $this->issueInvoiceFor($this->tenant, $customer->id);

        $orderUuid = $this->context->runAs(
            $this->tenant,
            fn () => Document::query()->where('number', $number)->firstOrFail()->documentOrderUuid(),
        );

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.$orderUuid));

        $response->assertOk();
        $response->assertSee(route('storefront.docs.download', ['number' => $number]), false);
    }

    public function test_the_order_detail_page_has_no_invoice_link_when_none_is_issued(): void
    {
        $customer = $this->makeCustomer($this->tenant);

        $order = $this->context->runAs($this->tenant, function () use ($customer): Order {
            return Order::query()->create([
                'number' => '2026'.random_int(100000, 999999),
                'checkout_token' => bin2hex(random_bytes(20)),
                'customer_id' => $customer->id,
                'email' => 'jana@example.cz',
                'billing' => [
                    'name' => 'Jana Nováková',
                    'street' => 'Hlavní 1',
                    'city' => 'Praha',
                    'zip' => '110 00',
                    'country' => 'CZ',
                ],
                'currency' => 'CZK',
                'items_total' => 10000,
                'total' => 10000,
                'placed_at' => now(),
            ]);
        });

        $response = $this->actingAsCustomer($customer)->get($this->url('/ucet/objednavky/'.$order->uuid));

        $response->assertOk();
        $response->assertDontSee('Stáhnout fakturu');
    }
}
