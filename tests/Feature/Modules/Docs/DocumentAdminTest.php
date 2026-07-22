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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
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
 * Wave 1.5 Task 7 — the admin surface over documents: list, manual issue,
 * PDF download, resend. QUEUE_CONNECTION=sync in phpunit.xml, so a store()
 * call's GenerateInvoicePdf::dispatch() runs inline, exactly like
 * GenerateInvoicePdfTest and InvoiceIssuerTest rely on.
 */
class DocumentAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

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

        foreach (['checkout', 'shipping', 'orders', 'docs'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/docs'.$path;
    }

    private function staffWith(array $permissions): User
    {
        $staff = User::factory()->create();

        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => $permissions,
            'joined_at' => now(),
        ]);

        return $staff;
    }

    /**
     * Places and pays an order (COD — no bank account, so no QR to worry
     * about) for the given tenant, mirroring InvoiceIssuerTest::placePaidOrder().
     */
    private function placePaidOrder(Tenant $tenant): Order
    {
        return $this->context->runAs($tenant, function (): Order {
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

            return $order;
        });
    }

    // --- store (issue) ------------------------------------------------------

    public function test_the_owner_can_issue_a_document_for_an_order(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $order = $this->placePaidOrder($this->tenant);

        $this->actingAs($this->owner)
            ->post($this->url(), ['order_uuid' => $order->uuid])
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('documents', [
            'order_id' => $order->id,
            'type' => Document::TYPE_INVOICE,
        ]));
    }

    public function test_issuing_twice_for_the_same_order_stays_idempotent(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $order = $this->placePaidOrder($this->tenant);

        $this->actingAs($this->owner)->post($this->url(), ['order_uuid' => $order->uuid])->assertRedirect();
        $this->actingAs($this->owner)->post($this->url(), ['order_uuid' => $order->uuid])->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertSame(
            1,
            Document::query()->where('order_id', $order->id)->count(),
        ));
    }

    public function test_issuing_is_forbidden_without_the_manage_permission(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $staff = $this->staffWith([]);
        $order = $this->placePaidOrder($this->tenant);

        $this->actingAs($staff)
            ->post($this->url(), ['order_uuid' => $order->uuid])
            ->assertForbidden();

        $this->context->runAs($this->tenant, fn () => $this->assertSame(
            0,
            Document::query()->where('order_id', $order->id)->count(),
        ));
    }

    public function test_a_guest_is_redirected_to_login_rather_than_issuing(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $order = $this->placePaidOrder($this->tenant);

        $this->post($this->url(), ['order_uuid' => $order->uuid])
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertSame(
            0,
            Document::query()->where('order_id', $order->id)->count(),
        ));
    }

    public function test_issuing_for_an_unknown_order_uuid_fails_validation(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), ['order_uuid' => '00000000-0000-0000-0000-000000000000'])
            ->assertSessionHasErrors('order_uuid');
    }

    // --- index ---------------------------------------------------------------

    public function test_the_listing_renders_issued_documents_for_a_user_with_the_manage_permission(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $order = $this->placePaidOrder($this->tenant);
        $this->context->runAs($this->tenant, fn () => app(DocumentIssuer::class)->issue($order->uuid));

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Docs/Index')
                ->has('documents.data', 1)
            );
    }

    public function test_the_listing_does_not_leak_another_shops_documents(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create([
            'billing_name' => 'Shop Two s.r.o.',
            'billing_address' => ['street' => 'Vedlejší 2', 'city' => 'Brno', 'zip' => '602 00', 'country' => 'CZ'],
        ]);
        foreach (['checkout', 'shipping', 'orders', 'docs'] as $module) {
            $this->activateModule($other, $module);
        }

        $ours = $this->placePaidOrder($this->tenant);
        $this->context->runAs($this->tenant, fn () => app(DocumentIssuer::class)->issue($ours->uuid));

        $theirs = $this->placePaidOrder($other);
        $this->context->runAs($other, fn () => app(DocumentIssuer::class)->issue($theirs->uuid));

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page->has('documents.data', 1));
    }

    // --- download --------------------------------------------------------------

    public function test_download_returns_the_pdf_bytes(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $order = $this->placePaidOrder($this->tenant);
        $number = $this->context->runAs(
            $this->tenant,
            fn () => app(DocumentIssuer::class)->issue($order->uuid)->documentNumber(),
        );

        $response = $this->actingAs($this->owner)->get($this->url('/'.$number.'/pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    public function test_download_of_a_foreign_tenants_document_is_not_found(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create([
            'billing_name' => 'Shop Two s.r.o.',
            'billing_address' => ['street' => 'Vedlejší 2', 'city' => 'Brno', 'zip' => '602 00', 'country' => 'CZ'],
        ]);
        foreach (['checkout', 'shipping', 'orders', 'docs'] as $module) {
            $this->activateModule($other, $module);
        }

        $foreignOrder = $this->placePaidOrder($other);
        $number = $this->context->runAs(
            $other,
            fn () => app(DocumentIssuer::class)->issue($foreignOrder->uuid)->documentNumber(),
        );

        // Requested against shop1's host, where a document with this number
        // (foreign tenant_id) is invisible to the BelongsToTenant scope.
        $this->actingAs($this->owner)
            ->get($this->url('/'.$number.'/pdf'))
            ->assertNotFound();
    }

    public function test_download_is_forbidden_without_the_manage_permission(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $staff = $this->staffWith([]);
        $order = $this->placePaidOrder($this->tenant);
        $number = $this->context->runAs(
            $this->tenant,
            fn () => app(DocumentIssuer::class)->issue($order->uuid)->documentNumber(),
        );

        $this->actingAs($staff)
            ->get($this->url('/'.$number.'/pdf'))
            ->assertForbidden();
    }

    public function test_a_signed_in_non_member_gets_a_403_not_a_pdf(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        // Authenticated, but never attached to this tenant — EnsureTenantMember
        // refuses at the membership check (403), before the controller (and
        // its own docs.manage check) is ever reached.
        $stranger = User::factory()->create();
        $order = $this->placePaidOrder($this->tenant);
        $number = $this->context->runAs(
            $this->tenant,
            fn () => app(DocumentIssuer::class)->issue($order->uuid)->documentNumber(),
        );

        $this->actingAs($stranger)
            ->get($this->url('/'.$number.'/pdf'))
            ->assertForbidden();
    }

    // --- resend ------------------------------------------------------------

    public function test_resend_re_dispatches_the_pdf_job_and_redirects(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $order = $this->placePaidOrder($this->tenant);
        $number = $this->context->runAs(
            $this->tenant,
            fn () => app(DocumentIssuer::class)->issue($order->uuid)->documentNumber(),
        );

        $this->actingAs($this->owner)
            ->post($this->url('/'.$number.'/odeslat'))
            ->assertRedirect();
    }

    public function test_resend_is_forbidden_without_the_manage_permission(): void
    {
        Storage::fake(FileStorage::PRIVATE_DISK);

        $staff = $this->staffWith([]);
        $order = $this->placePaidOrder($this->tenant);
        $number = $this->context->runAs(
            $this->tenant,
            fn () => app(DocumentIssuer::class)->issue($order->uuid)->documentNumber(),
        );

        $this->actingAs($staff)
            ->post($this->url('/'.$number.'/odeslat'))
            ->assertForbidden();
    }
}
