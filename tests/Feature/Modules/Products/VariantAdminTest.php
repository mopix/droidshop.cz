<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductOption;
use Modules\Products\Models\ProductOptionValue;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class VariantAdminTest extends TestCase
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

    private function product(): Product
    {
        return $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Tričko Acme',
            'price' => 499_00,
            'tax_rate_id' => $this->rateId(),
            'status' => Product::STATUS_ACTIVE,
        ]));
    }

    public function test_an_owner_can_add_an_axis_a_value_and_generate_variants(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner)
            ->post($this->url('/'.$product->slug.'/varianty/osy'), ['name' => 'Velikost'])
            ->assertRedirect();

        $option = $this->context->runAs($this->tenant, fn () => ProductOption::query()->firstOrFail());

        $this->actingAs($this->owner)
            ->post($this->url('/'.$product->slug."/varianty/osy/{$option->id}/hodnoty"), ['value' => 'M'])
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->post($this->url('/'.$product->slug.'/varianty/generovat'))
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () {
            $this->assertSame(1, ProductVariant::query()->count());
        });
    }

    public function test_an_option_can_be_renamed(): void
    {
        $product = $this->product();

        $option = $this->context->runAs($this->tenant, fn () => ProductOption::create([
            'product_id' => $product->id,
            'name' => 'Velikost',
            'position' => 0,
        ]));

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug."/varianty/osy/{$option->id}"), ['name' => 'Barva'])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($option) {
            $this->assertSame('Barva', $option->fresh()->name);
        });
    }

    public function test_a_variant_can_be_deleted(): void
    {
        $product = $this->product();

        $variant = $this->context->runAs($this->tenant, fn () => ProductVariant::create([
            'product_id' => $product->id,
            'position' => 0,
        ]));

        $this->actingAs($this->owner)
            ->delete($this->url('/'.$product->slug."/varianty/{$variant->id}"))
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($variant) {
            $this->assertSame(0, ProductVariant::query()->whereKey($variant->id)->count());
        });
    }

    public function test_an_option_can_be_deleted(): void
    {
        $product = $this->product();

        $option = $this->context->runAs($this->tenant, fn () => ProductOption::create([
            'product_id' => $product->id,
            'name' => 'Velikost',
            'position' => 0,
        ]));

        $this->actingAs($this->owner)
            ->delete($this->url('/'.$product->slug."/varianty/osy/{$option->id}"))
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($option) {
            $this->assertSame(0, ProductOption::query()->whereKey($option->id)->count());
        });
    }

    public function test_an_option_can_be_moved_down_and_up(): void
    {
        $product = $this->product();

        [$first, $second] = $this->context->runAs($this->tenant, fn () => [
            ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]),
            ProductOption::create(['product_id' => $product->id, 'name' => 'Barva', 'position' => 1]),
        ]);

        $this->actingAs($this->owner)
            ->post($this->url('/'.$product->slug."/varianty/osy/{$first->id}/poradi"), ['direction' => 'down'])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($first, $second) {
            $this->assertSame(1, $first->fresh()->position);
            $this->assertSame(0, $second->fresh()->position);
        });
    }

    public function test_a_value_can_be_deleted(): void
    {
        $product = $this->product();

        $option = $this->context->runAs($this->tenant, fn () => ProductOption::create([
            'product_id' => $product->id,
            'name' => 'Velikost',
            'position' => 0,
        ]));
        $value = $this->context->runAs($this->tenant, fn () => $option->values()->create(['value' => 'M', 'position' => 0]));

        $this->actingAs($this->owner)
            ->delete($this->url('/'.$product->slug."/varianty/osy/{$option->id}/hodnoty/{$value->id}"))
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($value) {
            $this->assertSame(0, ProductOptionValue::query()->whereKey($value->id)->count());
        });
    }

    public function test_a_value_can_be_moved_down_and_up(): void
    {
        $product = $this->product();

        $option = $this->context->runAs($this->tenant, fn () => ProductOption::create([
            'product_id' => $product->id,
            'name' => 'Velikost',
            'position' => 0,
        ]));
        [$first, $second] = $this->context->runAs($this->tenant, fn () => [
            $option->values()->create(['value' => 'M', 'position' => 0]),
            $option->values()->create(['value' => 'L', 'position' => 1]),
        ]);

        $this->actingAs($this->owner)
            ->post($this->url('/'.$product->slug."/varianty/osy/{$option->id}/hodnoty/{$first->id}/poradi"), ['direction' => 'down'])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () use ($first, $second) {
            $this->assertSame(1, $first->fresh()->position);
            $this->assertSame(0, $second->fresh()->position);
        });
    }

    public function test_a_guest_cannot_touch_the_variant_endpoints(): void
    {
        $product = $this->product();

        $this->post($this->url('/'.$product->slug.'/varianty/osy'), ['name' => 'Velikost'])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () {
            $this->assertSame(0, ProductOption::query()->count());
        });
    }

    public function test_a_signed_in_non_member_of_the_tenant_gets_a_403(): void
    {
        // EnsureTenantMember refuses at the membership check (403), before the
        // controller is ever reached — same convention as DocumentAdminTest's
        // "signed in non member gets a 403 not a pdf". Membership is checked
        // against the tenant resolved from the Host header, not against
        // whichever product id is in the URL, so this is a 403, not a 404.
        $product = $this->product();

        $other = User::factory()->create();
        $otherTenant = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($otherTenant, 'products');
        $otherTenant->users()->attach($other, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($other)
            ->post($this->url('/'.$product->slug.'/varianty/osy'), ['name' => 'Velikost'])
            ->assertForbidden();
    }

    public function test_a_product_of_another_shop_is_not_reachable(): void
    {
        // This is the actual cross-tenant-data isolation case for this
        // controller: the acting owner *is* a member of $this->tenant, but the
        // product id in the URL belongs to a different tenant. Product is
        // BelongsToTenant-scoped, so route model binding never finds it under
        // the current tenant context — a straight 404, with no controller code
        // involved.
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();
        $this->activateModule($other, 'products');

        $foreign = $this->context->runAs($other, fn () => app(ProductWriter::class)->create([
            'name' => 'Cizí produkt',
            'price' => 100_00,
            'tax_rate_id' => $this->rateId(),
        ]));

        $this->actingAs($this->owner)
            ->post($this->url('/'.$foreign->slug.'/varianty/osy'), ['name' => 'Velikost'])
            ->assertNotFound();
    }

    public function test_an_option_id_from_another_product_is_a_404(): void
    {
        $product = $this->product();

        $foreignProduct = $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Jiný produkt',
            'price' => 100_00,
            'tax_rate_id' => $this->rateId(),
        ]));

        $foreignOption = $this->context->runAs($this->tenant, fn () => ProductOption::create([
            'product_id' => $foreignProduct->id,
            'name' => 'Velikost',
            'position' => 0,
        ]));

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug."/varianty/osy/{$foreignOption->id}"), ['name' => 'Barva'])
            ->assertNotFound();
    }

    public function test_a_value_id_belonging_to_another_products_option_is_a_404(): void
    {
        $product = $this->product();

        $ownOption = $this->context->runAs($this->tenant, fn () => ProductOption::create([
            'product_id' => $product->id,
            'name' => 'Velikost',
            'position' => 0,
        ]));

        $foreignProduct = $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Jiný produkt',
            'price' => 100_00,
            'tax_rate_id' => $this->rateId(),
        ]));
        $foreignOption = $this->context->runAs($this->tenant, fn () => ProductOption::create([
            'product_id' => $foreignProduct->id,
            'name' => 'Velikost',
            'position' => 0,
        ]));
        $foreignValue = $this->context->runAs($this->tenant, fn () => $foreignOption->values()->create([
            'value' => 'M', 'position' => 0,
        ]));

        // Own option, but the value belongs to another product's axis.
        $this->actingAs($this->owner)
            ->delete($this->url('/'.$product->slug."/varianty/osy/{$ownOption->id}/hodnoty/{$foreignValue->id}"))
            ->assertNotFound();
    }

    public function test_a_variant_id_from_another_product_is_a_404(): void
    {
        $product = $this->product();

        $foreignProduct = $this->context->runAs($this->tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Jiný produkt',
            'price' => 100_00,
            'tax_rate_id' => $this->rateId(),
        ]));
        $foreignVariant = $this->context->runAs($this->tenant, fn () => ProductVariant::create([
            'product_id' => $foreignProduct->id,
            'position' => 0,
        ]));

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug."/varianty/{$foreignVariant->id}"), ['price' => 100])
            ->assertNotFound();
    }

    public function test_the_variant_price_must_be_a_non_negative_integer_or_empty(): void
    {
        $product = $this->product();

        $variant = $this->context->runAs($this->tenant, fn () => ProductVariant::create([
            'product_id' => $product->id,
            'position' => 0,
        ]));

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug."/varianty/{$variant->id}"), ['price' => -100])
            ->assertSessionHasErrors('price');

        $this->actingAs($this->owner)
            ->patch($this->url('/'.$product->slug."/varianty/{$variant->id}"), ['price' => null])
            ->assertSessionDoesntHaveErrors('price');
    }

    public function test_the_product_page_exposes_the_variant_matrix_to_the_editor(): void
    {
        $product = $this->product();

        $this->context->runAs($this->tenant, function () use ($product) {
            $option = ProductOption::create(['product_id' => $product->id, 'name' => 'Velikost', 'position' => 0]);
            $value = $option->values()->create(['value' => 'M', 'position' => 0]);
            $variant = ProductVariant::create(['product_id' => $product->id, 'position' => 0]);
            $variant->optionValues()->attach($value->id);
        });

        $this->actingAs($this->owner)
            ->get($this->url('/'.$product->slug))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Products/Show')
                ->has('options', 1)
                ->has('variants', 1)
            );
    }
}
