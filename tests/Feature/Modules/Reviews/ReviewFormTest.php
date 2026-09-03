<?php

namespace Tests\Feature\Modules\Reviews;

use App\Core\Settings\SettingsService;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Models\ReviewAggregate;
use Modules\Reviews\Models\ReviewInvitation;
use Modules\Reviews\Services\InvitationIssuer;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ReviewFormTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private string $token;

    private int $orderId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'reviews');
        $this->activateModule($this->tenant, 'products');

        // Objednávka s jednou položkou — tvar převezmi z tests/Feature/Modules/Orders/.
        // Naplň $this->orderId a $this->productId.
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            // Product::factory() does not exist for this model — products are
            // created through ProductWriter, the same as every other feature
            // test that seeds the catalogue (see XmlOutputInvalidationTest).
            $product = app(ProductWriter::class)->create([
                'name' => 'Mechanická klávesnice',
                'slug' => 'mechanicka-klavesnice',
                'price' => 199900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            $this->productId = (int) $product->getKey();

            $order = Order::query()->create([
                'number' => '2026'.random_int(1000, 9999),
                'checkout_token' => Str::random(40),
                'email' => 'jana@example.cz',
                'billing' => [
                    'name' => 'Jana Nováková',
                    'street' => 'Hlavní 1',
                    'city' => 'Praha',
                    'zip' => '110 00',
                    'country' => 'CZ',
                ],
                'currency' => 'CZK',
                'items_total' => 199900,
                'total' => 199900,
                'placed_at' => now(),
            ]);

            $order->items()->create([
                'product_id' => $this->productId,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit_price' => 199900,
                'tax_rate' => 21.00,
                'quantity' => 1,
                'line_total' => 199900,
                'currency' => 'CZK',
            ]);

            $this->orderId = $order->id;
        });

        app(TenantContext::class)->set($this->tenant);
        $this->token = app(InvitationIssuer::class)->issue($this->orderId)['token'];
        app(TenantContext::class)->forget();
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/recenze/'.$this->token.$path;
    }

    public function test_the_form_renders_the_purchased_product_in_plain_html(): void
    {
        $response = $this->get($this->url());

        $response->assertOk();
        // Blade SSR: obsah musí být v první odpovědi, ne dotažený JS.
        $response->assertSee('Vaše hodnocení', escape: false);
        $response->assertSee('noindex', escape: false);
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get('http://shop1.droidshop/recenze/naprosto-vymysleny')->assertNotFound();
    }

    public function test_an_expired_token_is_a_404(): void
    {
        ReviewInvitation::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->update(['expires_at' => now()->subDay()]);

        $this->get($this->url())->assertNotFound();
    }

    public function test_a_review_can_be_written_for_a_purchased_product(): void
    {
        $this->post($this->url(), [
            'shop' => ['rating' => 5],
            'products' => [
                $this->productId => ['rating' => 4, 'body' => 'Sedí přesně.'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('reviews', [
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->productId,
            'rating' => 4,
            'status' => Review::STATUS_PENDING,
            'verified_purchase' => true,
        ]);
    }

    public function test_a_product_that_was_not_in_the_order_is_refused(): void
    {
        $this->post($this->url(), [
            'products' => [
                999999 => ['rating' => 5, 'body' => 'Cizí produkt.'],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_the_token_is_single_use(): void
    {
        $this->post($this->url(), [
            'products' => [$this->productId => ['rating' => 4]],
        ])->assertOk();

        $this->post($this->url(), [
            'products' => [$this->productId => ['rating' => 1]],
        ])->assertNotFound();

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_a_submitted_review_is_not_visible_anywhere_until_published(): void
    {
        $this->post($this->url(), [
            'products' => [$this->productId => ['rating' => 4, 'body' => 'Zatím neschváleno.']],
        ]);

        $this->assertSame(
            0,
            ReviewAggregate::withoutGlobalScopes()
                ->where('tenant_id', $this->tenant->id)
                ->sum('rating_count'),
        );
    }

    public function test_a_shop_that_requires_text_refuses_bare_stars(): void
    {
        // reviews.min_body_length = 20 pro tenanta; tvar zápisu nastavení
        // zkopíruj z testů modulu Docs.
        app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(SettingsService::class)->set('reviews', 'min_body_length', 20),
        );

        $this->post($this->url(), [
            'products' => [$this->productId => ['rating' => 5]],
        ])->assertSessionHasErrors('products.'.$this->productId.'.body');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_a_shop_rating_is_dropped_when_the_shop_does_not_collect_them(): void
    {
        // reviews.shop_reviews_enabled = false pro tenanta.
        app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(SettingsService::class)->set('reviews', 'shop_reviews_enabled', false),
        );

        $this->post($this->url(), [
            'shop' => ['rating' => 5],
            'products' => [$this->productId => ['rating' => 4]],
        ])->assertOk();

        $this->assertDatabaseMissing('reviews', [
            'tenant_id' => $this->tenant->id,
            'subject' => Review::SUBJECT_SHOP,
        ]);
    }

    public function test_script_tags_in_the_body_do_not_survive(): void
    {
        $this->post($this->url(), [
            'products' => [
                $this->productId => ['rating' => 5, 'body' => 'Dobré <script>alert(1)</script> zboží'],
            ],
        ]);

        $review = Review::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->firstOrFail();

        $this->assertStringNotContainsString('<script>', $review->body);
    }
}
