<?php

namespace Tests\Feature\Modules\Checkout;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\Checkout\Contracts\CartRepository;
use App\Core\Checkout\NullCartRepository;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Checkout\Models\Cart;
use Modules\Checkout\Models\CartItem;
use Modules\Checkout\Services\EloquentCartRepository;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Modules\Storefront\Support\ShopModules;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class CartRepositoryTest extends TestCase
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

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'checkout');
    }

    private function repository(): CartRepository
    {
        return app(CartRepository::class);
    }

    private function rateId(): int
    {
        return app(TaxRates::class)->default()->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        return $this->context->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Notebook Acme 14',
            'price' => 24_990_00,
            'tax_rate_id' => $this->rateId(),
            ...$attributes,
        ]));
    }

    public function test_for_token_null_creates_a_new_cart_with_a_random_token_and_a_two_week_expiry(): void
    {
        $cartA = $this->context->runAs($this->tenant, fn () => $this->repository()->forToken(null));
        $cartB = $this->context->runAs($this->tenant, fn () => $this->repository()->forToken(null));

        $this->assertNotSame($cartA->cartId(), $cartB->cartId());
        $this->assertNotSame($cartA->cartToken(), $cartB->cartToken());
        $this->assertSame(40, strlen($cartA->cartToken()));
        $this->assertNotNull($cartA->cartExpiresAt());
        $this->assertEqualsWithDelta(
            now()->addDays(14)->timestamp,
            $cartA->cartExpiresAt()->timestamp,
            5,
        );
    }

    public function test_for_token_with_an_existing_token_returns_the_same_cart(): void
    {
        $created = $this->context->runAs($this->tenant, fn () => $this->repository()->forToken(null));

        $found = $this->context->runAs($this->tenant, fn () => $this->repository()->forToken($created->cartToken()));

        $this->assertSame($created->cartId(), $found->cartId());
    }

    public function test_a_foreign_tenants_token_does_not_resolve_and_a_new_cart_is_made_instead(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'checkout');

        $cartA = $this->context->runAs($this->tenant, fn () => $this->repository()->forToken(null));

        $cartB = $this->context->runAs($other, fn () => $this->repository()->forToken($cartA->cartToken()));

        $this->assertNotSame($cartA->cartId(), $cartB->cartId());
        $this->assertNotSame($cartA->cartToken(), $cartB->cartToken());
    }

    public function test_add_item_snapshots_the_catalog_price_and_a_second_call_merges_quantity_instead_of_a_new_row(): void
    {
        $product = $this->makeProduct($this->tenant, ['price' => 19_900_00]);

        $this->context->runAs($this->tenant, function () use ($product) {
            $cart = $this->repository()->forToken(null);

            $this->repository()->addItem($cart, $product->id, 1);
            $this->repository()->addItem($cart, $product->id, 2);

            // cartItems() always issues a fresh query (see CartShape's
            // docblock), so no refresh() is needed to see the merge above.
            $items = $cart->cartItems();

            $this->assertCount(1, $items);

            $item = $items->first();
            $this->assertSame(3, $item->quantity);
            $this->assertSame(19_900_00, $item->unit_price->amount);
            $this->assertSame('CZK', $item->unit_price->currency);
        });
    }

    /**
     * A real two-request race is not deterministic under single-threaded
     * PHPUnit, so the collision is forced instead of raced: a first
     * addItem() creates the row normally, then a repository subclass whose
     * existingItem() lookup is stubbed to miss once calls addItem() for the
     * same product — its create() therefore collides with the already-
     * committed row on cart_item_unique, the exact state a losing
     * concurrent "add to cart" double-click would be in. The catch must
     * recover by merging into the winning row instead of throwing.
     */
    public function test_a_concurrent_add_item_collision_merges_quantity_instead_of_throwing(): void
    {
        $product = $this->makeProduct($this->tenant, ['price' => 10_000]);

        $this->context->runAs($this->tenant, function () use ($product) {
            $cart = $this->repository()->forToken(null);

            // The winner: a normal addItem() creates the row.
            $this->repository()->addItem($cart, $product->id, 1);

            // The loser: its first existingItem() lookup is forced to miss,
            // so it proceeds straight to create() and collides.
            $racy = new class(app(ShopModules::class), app(ProductCatalog::class)) extends EloquentCartRepository
            {
                public int $lookupCalls = 0;

                protected function existingItem(Cart $cart, int $productId): ?CartItem
                {
                    $this->lookupCalls++;

                    if ($this->lookupCalls === 1) {
                        return null;
                    }

                    return parent::existingItem($cart, $productId);
                }
            };

            $racy->addItem($cart, $product->id, 2);

            $items = $cart->cartItems();
            $this->assertCount(1, $items);
            $this->assertSame(3, $items->first()->quantity);
        });
    }

    public function test_set_quantity_to_zero_removes_the_row_and_remove_item_removes_it(): void
    {
        $product = $this->makeProduct($this->tenant);

        $this->context->runAs($this->tenant, function () use ($product) {
            $cart = $this->repository()->forToken(null);
            $this->repository()->addItem($cart, $product->id, 2);
            $item = $cart->cartItems()->first();

            $this->repository()->setQuantity($cart, $item->id, 0);

            $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);

            $this->repository()->addItem($cart, $product->id, 1);
            $second = $cart->cartItems()->first();

            $this->repository()->removeItem($cart, $second->id);

            $this->assertDatabaseMissing('cart_items', ['id' => $second->id]);
        });
    }

    public function test_a_tenant_without_the_module_active_gets_a_transient_cart_that_is_never_persisted(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        // checkout module is granted in the plan by setUp()'s dependency
        // closure for $this->tenant only; $other never activates it.

        $cart = $this->context->runAs($other, fn () => $this->repository()->forToken(null));

        $this->assertNull($cart->cartId());
        $this->assertNotNull($cart->cartToken());
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_the_kernel_null_binding_answers_a_transient_cart_and_no_ops(): void
    {
        // On a deploy without the checkout module the module provider never
        // registers, so the container keeps the kernel's null binding. The
        // module provider always loads from disk in these tests, so we assert
        // the null class directly rather than trying to unregister it. This
        // must not touch Modules\Checkout\Models\Cart at all — that class is
        // exactly what a deploy without the module does not have.
        $repository = new NullCartRepository;

        $cart = $repository->forToken(null);

        $this->assertNull($cart->cartId());
        $this->assertNotNull($cart->cartToken());
        $this->assertTrue($cart->cartItems()->isEmpty());

        // No-ops must not throw, even against a cart with no tenant context.
        $repository->addItem($cart, 1, 1);
        $repository->setQuantity($cart, 1, 1);
        $repository->removeItem($cart, 1);
        $repository->attachToCustomer($cart, 1);

        $this->assertTrue(true);
    }
}
