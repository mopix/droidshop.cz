<?php

namespace Tests\Feature\Storefront;

use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pages\Models\Page;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Shipping\Models\PaymentMethod;
use Modules\Shipping\Models\ShippingMethod;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * A customer has to be able to find the shop's terms and privacy notice from
 * any page, and the footer is where people look. Published pages only: an
 * unfinished draft in the footer is worse than no link at all.
 */
class FooterLegalLinksTest extends TestCase
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
        $this->activateModule($this->tenant, 'storefront');
    }

    private function publish(string $slug, string $title, bool $published = true): void
    {
        $this->context->runAs($this->tenant, fn () => Page::query()->updateOrCreate(
            ['slug' => $slug],
            ['title' => $title, 'body' => '<p>Obsah.</p>', 'is_published' => $published],
        ));
    }

    public function test_a_published_page_is_linked_from_the_footer(): void
    {
        $this->activateModule($this->tenant, 'pages');
        $this->publish('obchodni-podminky', 'Obchodní podmínky');

        $this->get('http://obchod.droidshop/')
            ->assertOk()
            ->assertSee('Obchodní podmínky')
            ->assertSee('href="http://obchod.droidshop/obchodni-podminky"', escape: false);
    }

    public function test_an_unpublished_page_is_not_linked(): void
    {
        $this->activateModule($this->tenant, 'pages');
        $this->publish('reklamacni-rad', 'Reklamační řád', published: false);

        $this->get('http://obchod.droidshop/')
            ->assertOk()
            ->assertDontSee('Reklamační řád');
    }

    public function test_a_shop_without_the_pages_module_still_renders(): void
    {
        $this->get('http://obchod.droidshop/')
            ->assertOk()
            ->assertSee($this->tenant->name);
    }

    /**
     * The checkout consent is the one place a customer is explicitly asked to
     * agree to the terms, so it has to link them.
     *
     * Worth its own test because the mechanism is not obvious: the layout
     * composer sets $footerPages on storefront::layouts.shop, and in Blade a
     * child view renders before its layout, so the variable reaching
     * checkout/details is not something to assume.
     */
    public function test_the_checkout_consent_links_the_published_terms(): void
    {
        foreach (['pages', 'products', 'checkout', 'orders', 'shipping'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->publish('obchodni-podminky', 'Obchodní podmínky');
        $this->publish('ochrana-osobnich-udaju', 'Ochrana osobních údajů');

        $token = $this->cartWithOneItem();

        // Asserted on the link TEXT, not just the href: the footer of the same
        // page carries the same URLs, so an href-only assertion would pass
        // even if the consent itself stayed unlinked. The footer prints the
        // page title ("Obchodní podmínky"), the consent prints the inflected
        // form, so only one of them can match this.
        $this->withCookie('cart_token', $token)
            ->get('http://obchod.droidshop/pokladna/udaje')
            ->assertOk()
            ->assertSee('>obchodními podmínkami</a>', escape: false)
            ->assertSee('>zpracováním osobních údajů</a>', escape: false);
    }

    /**
     * Unpublished terms must leave plain text, never a link into a 404 — in
     * the one place the customer is asked to agree to them.
     */
    public function test_unpublished_terms_leave_the_consent_without_a_link(): void
    {
        foreach (['pages', 'products', 'checkout', 'orders', 'shipping'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->publish('obchodni-podminky', 'Obchodní podmínky', published: false);

        $token = $this->cartWithOneItem();

        $this->withCookie('cart_token', $token)
            ->get('http://obchod.droidshop/pokladna/udaje')
            ->assertOk()
            ->assertSee('Souhlasím', escape: false)
            ->assertDontSee('>obchodními podmínkami</a>', escape: false);
    }

    private function cartWithOneItem(): string
    {
        return $this->context->runAs($this->tenant, function (): string {
            $product = app(ProductWriter::class)->create([
                'name' => 'Klávesnice',
                'price' => 100_000,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            $carts = app(CartRepository::class);
            $cart = $carts->forToken(null);
            $carts->addItem($cart, $product->id, 1);

            $shipping = ShippingMethod::create([
                'provider' => ShippingMethod::PROVIDER_FLAT,
                'name' => 'Kurýr',
                'price' => 9_900,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'is_active' => true,
            ]);

            $payment = PaymentMethod::create([
                'provider' => PaymentMethod::PROVIDER_COD,
                'name' => 'Dobírka',
                'fee' => 0,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'is_active' => true,
                'position' => 1,
            ]);

            $carts->chooseShipping($cart, $shipping->id, $payment->id);

            return $cart->token;
        });
    }
}
