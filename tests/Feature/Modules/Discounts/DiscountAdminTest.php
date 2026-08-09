<?php

namespace Tests\Feature\Modules\Discounts;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Modules\Categories\Services\CategoryTree;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountTarget;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The admin CRUD screen over discounts (wave 2.6, task 12): coupons (have a
 * code) and automatic rules (do not) share this one screen.
 */
class DiscountAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);
        $this->activateModule($this->tenant, 'discounts');
        $this->activateModule($this->tenant, 'products');
        $this->activateModule($this->tenant, 'categories');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    public function test_the_owner_creates_a_coupon(): void
    {
        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/m/discounts', [
                'name' => 'Uvítací sleva',
                'code' => 'VITEJTE',
                'type' => 'percent',
                'value' => 100,
                'scope' => 'cart',
                'active' => true,
                'combinable' => true,
            ])
            ->assertRedirect();

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $discount = Discount::query()->firstOrFail();

            $this->assertSame('VITEJTE', $discount->code);
            $this->assertSame(100, (int) $discount->value);
        });
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('VITEJTE')->percent(100)->create();
        });

        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/m/discounts', [
                'name' => 'Druhá',
                'code' => 'VITEJTE',
                'type' => 'percent',
                'value' => 50,
                'scope' => 'cart',
            ])
            ->assertSessionHasErrors('code');
    }

    /**
     * Two different shops each running a code with the same spelling is legal
     * — uniqueness on `code` is scoped to the tenant, not global.
     */
    public function test_the_same_code_is_free_on_another_tenant(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('VITEJTE')->percent(100)->create();
        });

        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        $this->activateModule($other, 'discounts');
        $otherOwner = User::factory()->create();
        $other->users()->attach($otherOwner, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($otherOwner)
            ->post('http://shop2.droidshop/admin/m/discounts', [
                'name' => 'Uvítací sleva',
                'code' => 'VITEJTE',
                'type' => 'percent',
                'value' => 100,
                'scope' => 'cart',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('code');
    }

    public function test_a_user_of_another_tenant_is_forbidden(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get('http://shop1.droidshop/admin/m/discounts')
            ->assertForbidden();
    }

    public function test_the_screen_is_absent_when_the_module_is_off(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);
        $owner = User::factory()->create();
        $other->users()->attach($owner, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($owner)
            ->get('http://shop2.droidshop/admin/m/discounts')
            ->assertNotFound();
    }

    /**
     * A category id from a foreign tenant must never be trusted just because
     * it arrived in the request body — same stance as product/category ids
     * everywhere else in the admin (e.g. StoreProductRequest::category_ids).
     */
    public function test_a_target_id_from_another_tenant_is_rejected(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);

        $foreignCategoryId = app(TenantContext::class)->runAs(
            $other,
            fn () => app(CategoryTree::class)->create(['name' => 'Cizí kategorie'])->id,
        );

        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/m/discounts', [
                'name' => 'Sleva na kategorii',
                'type' => 'percent',
                'value' => 50,
                'scope' => 'categories',
                'targets' => [$foreignCategoryId],
            ])
            ->assertSessionHasErrors('targets.0');
    }

    /**
     * The mirror-image happy path: an id that genuinely belongs to this
     * tenant is accepted and persisted as a discount_targets row.
     */
    public function test_a_category_target_belonging_to_the_tenant_is_accepted(): void
    {
        $categoryId = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(CategoryTree::class)->create(['name' => 'Elektronika'])->id,
        );

        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/m/discounts', [
                'name' => 'Sleva na elektroniku',
                'type' => 'percent',
                'value' => 50,
                'scope' => 'categories',
                'targets' => [$categoryId],
            ])
            ->assertRedirect();

        app(TenantContext::class)->runAs($this->tenant, function () use ($categoryId): void {
            $discount = Discount::query()->firstOrFail();

            $this->assertSame(1, $discount->targets()->count());
            $this->assertSame(DiscountTarget::TYPE_CATEGORY, $discount->targets()->first()->target_type);
            $this->assertSame($categoryId, $discount->targets()->first()->target_id);
        });
    }

    /**
     * A discount scoped to the whole cart never carries targets — the picker
     * is not reachable in the UI for scope=cart, and a posted id must not be
     * trusted just because the request included one anyway.
     */
    public function test_a_target_id_is_rejected_when_the_scope_is_cart(): void
    {
        $categoryId = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(CategoryTree::class)->create(['name' => 'Elektronika'])->id,
        );

        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/m/discounts', [
                'name' => 'Sleva na košík',
                'type' => 'percent',
                'value' => 50,
                'scope' => 'cart',
                'targets' => [$categoryId],
            ])
            ->assertSessionHasErrors('targets.0');
    }

    public function test_the_owner_updates_a_discount(): void
    {
        $discount = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => Discount::factory()->code('STARY')->percent(50)->create(['name' => 'Starý název']),
        );

        $this->actingAs($this->owner)
            ->patch("http://shop1.droidshop/admin/m/discounts/{$discount->id}", [
                'name' => 'Nový název',
                'code' => 'STARY',
                'type' => 'percent',
                'value' => 200,
                'scope' => 'cart',
            ])
            ->assertRedirect();

        app(TenantContext::class)->runAs($this->tenant, function () use ($discount): void {
            $fresh = $discount->fresh();

            $this->assertSame('Nový název', $fresh->name);
            $this->assertSame(200, (int) $fresh->value);
        });
    }

    public function test_the_owner_deletes_a_discount(): void
    {
        $discount = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => Discount::factory()->code('SMAZAT')->percent(50)->create(),
        );

        $this->actingAs($this->owner)
            ->delete("http://shop1.droidshop/admin/m/discounts/{$discount->id}")
            ->assertRedirect();

        app(TenantContext::class)->runAs($this->tenant, function () use ($discount): void {
            $this->assertNull(Discount::query()->find($discount->id));
        });
    }

    /**
     * A discount id that belongs to another tenant must 404 through route
     * model binding (BelongsToTenant's global scope), the same guarantee
     * ShippingMethodAdminController and the products admin rely on.
     */
    public function test_a_discount_id_from_another_tenant_is_a_404(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create(['name' => 'Shop Two']);

        $foreign = app(TenantContext::class)->runAs(
            $other,
            fn () => Discount::factory()->code('CIZI')->percent(50)->create(),
        );

        $this->actingAs($this->owner)
            ->delete("http://shop1.droidshop/admin/m/discounts/{$foreign->id}")
            ->assertNotFound();
    }

    /**
     * `value` is permille for a percent discount, so 1000 already means "the
     * whole basket is free" — nothing above that is legitimate. Before this
     * fix the server accepted e.g. 5000 (500 %) and only the evaluator's
     * basket cap kept the money non-negative (final review, wave 2.6).
     */
    public function test_a_percent_value_over_100_percent_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/m/discounts', [
                'name' => 'Přehnaná sleva',
                'type' => 'percent',
                'value' => 5000,
                'scope' => 'cart',
            ])
            ->assertSessionHasErrors('value');
    }

    /**
     * The percent ceiling must not leak onto the fixed/haléře unit — a large
     * fixed discount is a legitimate amount, unlike a percent above 100.
     */
    public function test_a_large_fixed_amount_is_still_accepted(): void
    {
        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/m/discounts', [
                'name' => 'Velká pevná sleva',
                'type' => 'fixed',
                // 5 000 Kč — the ceiling is on haléře, and since wave 3.8 the
                // form takes korunas.
                'value' => '5000',
                'scope' => 'cart',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('value');
    }

    /**
     * The evaluator never reads a coupon's own `combinable` flag — only an
     * automatic rule's. A direct POST carrying `combinable=false` alongside
     * a code must not be allowed to store a flag nothing honours (final
     * review, wave 2.6): StoreDiscountRequest forces it back to true.
     */
    public function test_combinable_is_forced_true_for_a_coupon_regardless_of_what_was_posted(): void
    {
        $this->actingAs($this->owner)
            ->post('http://shop1.droidshop/admin/m/discounts', [
                'name' => 'Sleva s kódem',
                'code' => 'NEKOMBINOVAT',
                'type' => 'percent',
                'value' => 100,
                'scope' => 'cart',
                'combinable' => false,
            ])
            ->assertRedirect();

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $discount = Discount::query()->where('code', 'NEKOMBINOVAT')->firstOrFail();

            $this->assertTrue((bool) $discount->combinable);
        });
    }

    /**
     * The same enforcement on update: an existing coupon cannot be edited
     * back into `combinable=false` either.
     */
    public function test_combinable_is_forced_true_for_a_coupon_on_update(): void
    {
        $discount = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => Discount::factory()->code('DRZKOMBI')->percent(50)->create(['combinable' => true]),
        );

        $this->actingAs($this->owner)
            ->patch("http://shop1.droidshop/admin/m/discounts/{$discount->id}", [
                'name' => 'Sleva s kódem',
                'code' => 'DRZKOMBI',
                'type' => 'percent',
                'value' => 50,
                'scope' => 'cart',
                'combinable' => false,
            ])
            ->assertRedirect();

        app(TenantContext::class)->runAs($this->tenant, function () use ($discount): void {
            $this->assertTrue((bool) $discount->fresh()->combinable);
        });
    }

    /**
     * The product picker must never ship the whole catalogue (final review,
     * wave 2.6) — it searches on demand instead, capped and filtered exactly
     * like ProductAdminController::index()'s own search.
     */
    public function test_the_product_search_endpoint_is_bounded_and_filtered(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $rate = app(TaxRates::class)->default()->id;

            app(ProductWriter::class)->create(['name' => 'Hledaný produkt', 'price' => 100_00, 'tax_rate_id' => $rate]);
            app(ProductWriter::class)->create(['name' => 'Jiný produkt', 'price' => 100_00, 'tax_rate_id' => $rate]);
        });

        $response = $this->actingAs($this->owner)
            ->get('http://shop1.droidshop/admin/m/discounts/produkty/hledat?q=Hledaný')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains(fn ($name) => str_contains($name, 'Hledaný produkt')));
        $this->assertFalse($names->contains(fn ($name) => str_contains($name, 'Jiný produkt')));
    }

    /**
     * The edit screen's `selectedProducts` prop is bounded to this
     * discount's own targets, never the whole catalogue — the mirror-image
     * check to the search endpoint test above, at the Inertia prop level.
     */
    public function test_the_edit_screen_ships_only_the_selected_products(): void
    {
        [$targeted, $discount] = app(TenantContext::class)->runAs($this->tenant, function (): array {
            $rate = app(TaxRates::class)->default()->id;

            $targeted = app(ProductWriter::class)->create(['name' => 'Cílený produkt', 'price' => 100_00, 'tax_rate_id' => $rate]);
            app(ProductWriter::class)->create(['name' => 'Netknutý produkt', 'price' => 100_00, 'tax_rate_id' => $rate]);

            $discount = Discount::factory()->percent(100)->create(['scope' => Discount::SCOPE_PRODUCTS]);
            $discount->targets()->create([
                'target_type' => DiscountTarget::TYPE_PRODUCT,
                'target_id' => $targeted->id,
            ]);

            return [$targeted, $discount];
        });

        $this->actingAs($this->owner)
            ->get("http://shop1.droidshop/admin/m/discounts/{$discount->id}/upravit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Discounts/Form')
                ->has('selectedProducts', 1)
                ->where('selectedProducts.0.id', $targeted->id)
            );
    }
}
