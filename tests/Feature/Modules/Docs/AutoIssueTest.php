<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Orders\Contracts\OrderPlacement;
use App\Core\Orders\Contracts\OrderSettlement;
use App\Core\Orders\PlacementRequest;
use App\Core\Settings\SettingsService;
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
 * Wave 1.5 Task 4 — invoicing driven by a domain event out of OrderWorkflow,
 * not a direct call from checkout/payments.
 *
 * DB-backed against the same MySQL test database the suite uses: the
 * invariant under test (a real payment settlement, going through
 * EloquentOrderSettlement -> OrderWorkflow -> Event::dispatch, results in
 * exactly the invoice the tenant's auto_issue_on setting calls for) is a
 * property of the whole wired path, not something a mock of any one piece
 * would prove.
 */
class AutoIssueTest extends TestCase
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

        $this->activateModule($this->tenant, 'checkout');
        $this->activateModule($this->tenant, 'shipping');
        $this->activateModule($this->tenant, 'orders');
        $this->activateModule($this->tenant, 'docs');
    }

    // --- helpers ----------------------------------------------------------

    /**
     * Places a real order through the checkout path, left in its natural
     * unpaid state — so the settlement call below drives a genuine
     * unpaid -> paid transition through OrderWorkflow, the only path that
     * dispatches OrderPaymentSettled.
     */
    private function placeUnpaidOrder(): Order
    {
        return $this->context->runAs($this->tenant, function (): Order {
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
                customerId: null,
                source: 'storefront',
                note: null,
            ));

            return Order::query()->where('uuid', $placed->uuid())->firstOrFail();
        });
    }

    private function settlePaid(string $uuid): void
    {
        $this->context->runAs($this->tenant, fn () => app(OrderSettlement::class)->settlePaid($uuid, 'test'));
    }

    private function documentCount(int $orderId): int
    {
        return $this->context->runAs($this->tenant, fn () => Document::query()->where('order_id', $orderId)->count());
    }

    // --- scenarios --------------------------------------------------------

    public function test_paid_order_auto_issues_invoice_when_setting_is_paid(): void
    {
        $this->context->runAs($this->tenant, fn () => app(SettingsService::class)->set('docs', 'auto_issue_on', 'paid'));
        $order = $this->placeUnpaidOrder();

        $this->settlePaid($order->uuid);

        $this->assertSame(1, $this->documentCount($order->id));
    }

    public function test_manual_setting_does_not_auto_issue(): void
    {
        $this->context->runAs($this->tenant, fn () => app(SettingsService::class)->set('docs', 'auto_issue_on', 'manual'));
        $order = $this->placeUnpaidOrder();

        $this->settlePaid($order->uuid);

        $this->assertSame(0, $this->documentCount($order->id));
    }

    public function test_duplicate_settlement_issues_one_invoice(): void
    {
        $this->context->runAs($this->tenant, fn () => app(SettingsService::class)->set('docs', 'auto_issue_on', 'paid'));
        $order = $this->placeUnpaidOrder();

        $this->settlePaid($order->uuid);
        // Already paid: EloquentOrderSettlement's own guard no-ops before
        // OrderWorkflow is even called, so no second OrderPaymentSettled fires.
        $this->settlePaid($order->uuid);

        $this->assertSame(1, $this->documentCount($order->id));
    }
}
