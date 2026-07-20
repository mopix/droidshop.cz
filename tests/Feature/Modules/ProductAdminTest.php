<?php

namespace Tests\Feature\Modules;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ProductAdminTest extends TestCase
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

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'products');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/products'.$path;
    }

    private function rateId(): int
    {
        return app(TaxRates::class)->default()->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function make(array $attributes = []): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Notebook Acme 14',
            'price' => 24_990_00,
            'tax_rate_id' => $this->rateId(),
            ...$attributes,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Notebook Acme 14',
            'price' => 24_990_00,
            'tax_rate_id' => $this->rateId(),
            'status' => Product::STATUS_DRAFT,
            'stock_policy' => Product::STOCK_POLICY_SOLD_OUT,
            'weight_g' => 1800,
            ...$overrides,
        ];
    }

    public function test_the_listing_renders_with_pagination(): void
    {
        $this->make();

        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Products/Index')
                ->has('products.data', 1)
                ->has('filters')
            );
    }

    public function test_the_listing_can_be_filtered_by_status_and_searched(): void
    {
        $this->make(['status' => Product::STATUS_ACTIVE, 'sku' => 'NB-1']);
        $this->make(['name' => 'Myš', 'status' => Product::STATUS_DRAFT]);

        $this->actingAs($this->owner)
            ->get($this->url('?status=active'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('products.data', 1));

        $this->actingAs($this->owner)
            ->get($this->url('?search=NB-1'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('products.data', 1));
    }

    public function test_a_product_is_created(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), $this->payload())
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('products', [
            'slug' => 'notebook-acme-14',
            'price' => 24_990_00,
        ]));
    }

    public function test_a_negative_price_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), $this->payload(['price' => -1]))
            ->assertSessionHasErrors('price');
    }

    public function test_an_unknown_tax_rate_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), $this->payload(['tax_rate_id' => 99999]))
            ->assertSessionHasErrors('tax_rate_id');
    }

    public function test_an_absurd_weight_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), $this->payload(['weight_g' => 200001]))
            ->assertSessionHasErrors('weight_g');
    }

    public function test_an_ean_with_a_broken_checksum_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), $this->payload(['ean' => '1234567890123']))
            ->assertSessionHasErrors('ean');
    }

    public function test_a_valid_ean_passes(): void
    {
        // 4006381333931 is a textbook valid EAN-13.
        $this->actingAs($this->owner)
            ->post($this->url(), $this->payload(['ean' => '4006381333931']))
            ->assertSessionDoesntHaveErrors('ean');
    }

    public function test_the_purchase_price_is_hidden_without_the_costs_permission(): void
    {
        // Margin data. A staff member who may edit products has no business
        // seeing what the shop paid.
        $product = $this->make(['purchase_price' => 15_000_00]);

        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => ['products.view', 'products.edit'],
            'joined_at' => now(),
        ]);

        $this->actingAs($staff)
            ->get($this->url('/'.$product->slug))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('product.purchase_price', null)
                ->where('can.costs', false)
            );

        $this->actingAs($this->owner)
            ->get($this->url('/'.$product->slug))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('product.purchase_price', 15_000_00)
                ->where('can.costs', true)
            );
    }

    public function test_a_staff_member_cannot_write_the_purchase_price(): void
    {
        // Hiding the field in the UI is not the control; dropping it here is.
        $product = $this->make(['purchase_price' => 15_000_00]);

        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff',
            'permissions' => ['products.view', 'products.edit'],
            'joined_at' => now(),
        ]);

        $this->actingAs($staff)
            ->patch($this->url('/'.$product->slug), $this->payload(['purchase_price' => 1]))
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'purchase_price' => 15_000_00,
        ]));
    }

    public function test_reaching_the_plan_limit_blocks_creation_with_a_readable_error(): void
    {
        $plan = Plan::factory()->create(['limits' => ['products' => 1]]);
        $this->tenant->forceFill(['plan_id' => $plan->id])->save();
        $this->make();

        $this->actingAs($this->owner)
            ->post($this->url(), $this->payload(['name' => 'Druhý']))
            ->assertSessionHasErrors('name');
    }

    public function test_a_product_of_another_shop_is_not_reachable(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'products');

        $foreign = $this->context->runAs($other, fn () => app(ProductWriter::class)->create([
            'name' => 'Cizí produkt',
            'price' => 100_00,
            'tax_rate_id' => $this->rateId(),
        ]));

        $this->actingAs($this->owner)
            ->get($this->url('/'.$foreign->slug))
            ->assertNotFound();
    }

    public function test_deleting_a_product_asks_nothing_but_soft_deletes(): void
    {
        $product = $this->make();

        $this->actingAs($this->owner)
            ->delete($this->url('/'.$product->slug))
            ->assertRedirect();

        $this->context->runAs($this->tenant, fn () => $this->assertSoftDeleted('products', [
            'id' => $product->id,
        ]));
    }

    public function test_a_member_without_the_edit_permission_cannot_write(): void
    {
        $staff = User::factory()->create();
        $this->tenant->users()->attach($staff, [
            'role' => 'staff', 'permissions' => ['products.view'], 'joined_at' => now(),
        ]);

        $this->actingAs($staff)->get($this->url())->assertOk();
        $this->actingAs($staff)->post($this->url(), $this->payload())->assertForbidden();
    }

    public function test_the_listing_does_not_run_a_query_per_row(): void
    {
        foreach (range(1, 10) as $i) {
            $this->make(['name' => 'Produkt '.$i]);
        }

        DB::enableQueryLog();

        $this->actingAs($this->owner)->get($this->url())->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(20, $queries, 'The listing looks like it is loading relations per row.');
    }
}
