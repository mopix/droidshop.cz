<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Checkout\Models\Cart;
use Modules\Checkout\Models\CartItem;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductAddon;
use Modules\Products\Models\ProductAddonGroup;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * Accessories in the cart (wave 4.2, task C2).
 *
 * They are lines of their own rather than a surcharge folded into the
 * product's price, because that is how they have to reach the invoice — with
 * their own label and their own VAT rate. Everything here is driven over plain
 * HTTP, the way a shopper without JavaScript reaches it.
 */
class CartAddonTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private Product $product;

    private ProductAddonGroup $frames;

    private ProductAddon $oak;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();

        foreach (['storefront', 'products', 'checkout'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        $this->context->runAs($this->tenant, function (): void {
            $this->product = app(ProductWriter::class)->create([
                'name' => 'Obraz',
                'price' => 42900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            $this->frames = ProductAddonGroup::create([
                'product_id' => $this->product->id,
                'label' => 'Dekorativní rám',
                'required' => false,
                'position' => 0,
            ]);

            $this->oak = ProductAddon::create([
                'group_id' => $this->frames->id,
                'label' => 'Rám – dub',
                'price' => 26900,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'position' => 0,
            ]);
        });

        $this->context->forget();
    }

    private function add(array $payload = []): TestResponse
    {
        // The cart token travels in a cookie, and the test client does not
        // replay one on its own — so every request that has to reach the same
        // basket carries it explicitly, like a browser would.
        $token = $this->cartToken();

        $request = $token === null ? $this : $this->withCookie('cart_token', $token);

        return $request->post('http://shop1.droidshop/kosik', [
            'product_id' => $this->product->id,
            'quantity' => 1,
            ...$payload,
        ]);
    }

    private function cartToken(): ?string
    {
        return $this->context->runAs(
            $this->tenant,
            fn () => Cart::query()->value('token'),
        );
    }

    public function test_a_chosen_addon_becomes_its_own_line(): void
    {
        $this->add(['addon_id' => [$this->oak->id]])->assertRedirect();

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $this->product->id,
            'addon_id' => $this->oak->id,
            'unit_price' => 26900,
        ]);
    }

    public function test_the_cart_shows_the_addon_and_charges_for_it(): void
    {
        $this->add(['addon_id' => [$this->oak->id]]);

        $html = (string) $this->withCookie('cart_token', $this->cartToken())
            ->get('http://shop1.droidshop/kosik')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Rám – dub', $html);
        // 429 + 269 = 698 Kč, computed by the server from the catalogue.
        $this->assertMatchesRegularExpression('/698[\s\x{00a0}\x{202f}]?,?\d*\s*Kč/u', $html);
    }

    public function test_an_addon_of_another_product_is_dropped(): void
    {
        // Otherwise a crafted post buys one picture's cheap frame for another.
        $foreignAddon = $this->context->runAs($this->tenant, function (): ProductAddon {
            $other = app(ProductWriter::class)->create([
                'name' => 'Jiný obraz',
                'price' => 99900,
                'status' => Product::STATUS_ACTIVE,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
            ]);

            $group = ProductAddonGroup::create([
                'product_id' => $other->id,
                'label' => 'Rám',
                'required' => false,
                'position' => 0,
            ]);

            return ProductAddon::create([
                'group_id' => $group->id,
                'label' => 'Cizí rám',
                'price' => 100,
                'tax_rate_id' => app(TaxRates::class)->default()->id,
                'position' => 0,
            ]);
        });

        $this->add(['addon_id' => [$foreignAddon->id]])->assertRedirect();

        $this->assertDatabaseMissing('cart_items', ['addon_id' => $foreignAddon->id]);
    }

    public function test_a_required_group_cannot_be_skipped(): void
    {
        $this->context->runAs($this->tenant, fn () => $this->frames->update(['required' => true]));

        $this->add()->assertSessionHasErrors('addon_id');

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_the_same_product_with_and_without_an_addon_are_two_lines(): void
    {
        $this->add(['addon_id' => [$this->oak->id]]);
        $this->add();

        // Two product lines plus one accessory line.
        $this->assertDatabaseCount('cart_items', 3);
    }

    public function test_the_addon_follows_the_quantity_of_its_product(): void
    {
        $this->add(['addon_id' => [$this->oak->id]]);

        $parent = CartItem::query()
            ->withoutGlobalScopes()
            ->whereNull('parent_item_id')
            ->firstOrFail();

        $this->withCookie('cart_token', $this->cartToken())
            ->patch("http://shop1.droidshop/kosik/{$parent->id}", ['quantity' => 3]);

        $this->assertDatabaseHas('cart_items', [
            'addon_id' => $this->oak->id,
            'quantity' => 3,
        ]);
    }

    public function test_removing_the_product_removes_its_addon(): void
    {
        $this->add(['addon_id' => [$this->oak->id]]);

        $parent = CartItem::query()
            ->withoutGlobalScopes()
            ->whereNull('parent_item_id')
            ->firstOrFail();

        $this->withCookie('cart_token', $this->cartToken())
            ->delete("http://shop1.droidshop/kosik/{$parent->id}");

        $this->assertDatabaseCount('cart_items', 0);
    }
}
