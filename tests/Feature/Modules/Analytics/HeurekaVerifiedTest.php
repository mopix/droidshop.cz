<?php

namespace Tests\Feature\Modules\Analytics;

use App\Core\Settings\SettingsService;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Analytics\Services\HeurekaVerified;
use Modules\Orders\Models\Order;
use Modules\Pages\Support\PageTemplates;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Heureka Ověřeno zákazníky sits outside the consent gate on purpose: it
 * stores nothing in the browser, so § 89 odst. 3 does not apply and the
 * lawful basis is the tenant's legitimate interest. The customer has to be
 * able to find out and object — hence the paragraph in the privacy-notice
 * template — but not to be asked first.
 */
class HeurekaVerifiedTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();

        foreach (['storefront', 'products', 'orders', 'analytics'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        Http::fake([
            'ssl.heureka.cz/*' => Http::response('', 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function configure(array $values): void
    {
        $this->context->runAs($this->tenant, fn () => app(SettingsService::class)->setMany('analytics', $values));
    }

    private function order(): Order
    {
        return $this->context->runAs($this->tenant, function (): Order {
            $product = app(ProductWriter::class)->create([
                'name' => 'Klávesnice',
                'sku' => 'KB-1',
                'price' => 100_000,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            $order = Order::query()->create([
                'number' => 'OBJ-1',
                'email' => 'zakaznik@example.com',
                'items_total' => 100_000,
                'shipping_total' => 0,
                'total' => 100_000,
                'currency' => 'CZK',
                'fulfillment_status' => 'new',
                'payment_status' => 'unpaid',
                'checkout_token' => 'test-token-1',
                'billing' => ['name' => 'Jan Novák'],
            ]);

            $order->items()->create([
                'tenant_id' => $this->tenant->id,
                'product_id' => $product->id,
                'name' => 'Klávesnice',
                'sku' => 'KB-1',
                'quantity' => 1,
                'unit_price' => 100_000,
                'line_total' => 100_000,
                'tax_rate' => 21,
            ]);

            return $order->fresh();
        });
    }

    public function test_a_configured_shop_reports_the_order(): void
    {
        $this->configure(['heureka_enabled' => true, 'heureka_api_key' => 'klic-123']);

        $order = $this->order();

        $this->context->runAs($this->tenant, fn () => app(HeurekaVerified::class)->report($order));

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'ssl.heureka.cz')
                && $request['id'] === 'klic-123'
                && $request['email'] === 'zakaznik@example.com'
                && $request['orderId'] === 'OBJ-1';
        });
    }

    public function test_a_shop_with_the_switch_off_reports_nothing(): void
    {
        $this->configure(['heureka_enabled' => false, 'heureka_api_key' => 'klic-123']);

        $order = $this->order();

        $this->context->runAs($this->tenant, fn () => app(HeurekaVerified::class)->report($order));

        Http::assertNothingSent();
    }

    /**
     * A switch on with no key would report to nobody; failing silently here
     * is right, but it must not become an exception on a customer's order.
     */
    public function test_a_shop_without_a_key_reports_nothing(): void
    {
        $this->configure(['heureka_enabled' => true]);

        $order = $this->order();

        $this->context->runAs($this->tenant, fn () => app(HeurekaVerified::class)->report($order));

        Http::assertNothingSent();
    }

    /**
     * The key is a credential: it must never come back to the browser, on any
     * page — the storefront least of all.
     */
    public function test_the_key_never_appears_on_the_storefront(): void
    {
        $this->configure(['heureka_enabled' => true, 'heureka_api_key' => 'klic-123']);

        $html = (string) $this->get('http://obchod.droidshop/')->assertOk()->getContent();

        $this->assertStringNotContainsString('klic-123', $html);
    }

    /**
     * Heureka being down must never cost a customer their order — this runs
     * after the order is already committed, so the worst it may do is log.
     */
    public function test_a_failing_heureka_does_not_throw(): void
    {
        Http::fake([
            'ssl.heureka.cz/*' => fn () => throw new \RuntimeException('network down'),
        ]);

        $this->configure(['heureka_enabled' => true, 'heureka_api_key' => 'klic-123']);

        $order = $this->order();

        $this->context->runAs($this->tenant, fn () => app(HeurekaVerified::class)->report($order));

        $this->assertTrue(true, 'reporting must swallow transport failures');
    }

    /**
     * The privacy-notice template a tenant is seeded with has to name this,
     * or their customers have no way to learn about it or object.
     */
    public function test_the_privacy_template_mentions_the_questionnaire(): void
    {
        $template = PageTemplates::all()['ochrana-osobnich-udaju']['body'];

        $this->assertStringContainsString('Ověřeno zákazníky', $template);
        $this->assertStringContainsString('oprávněný zájem', $template);
    }
}
