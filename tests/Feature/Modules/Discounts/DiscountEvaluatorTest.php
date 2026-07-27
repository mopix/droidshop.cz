<?php

namespace Tests\Feature\Modules\Discounts;

use App\Core\Discounts\Contracts\DiscountEngine;
use App\Core\Discounts\DiscountContext;
use App\Core\Discounts\DiscountLine;
use App\Core\Discounts\DiscountRejection;
use App\Core\Money\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountTarget;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class DiscountEvaluatorTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->create(['name' => 'Shop One']);
        $this->activateModule($this->tenant, 'discounts');
    }

    private function context(
        ?string $code = null,
        ?int $customerId = null,
        ?string $email = null,
        ?array $lines = null,
    ): DiscountContext {
        $lines ??= [
            new DiscountLine(1, 10, null, [7], new Money(60000, 'CZK'), 21.0),
            new DiscountLine(2, 20, null, [9], new Money(40000, 'CZK'), 12.0),
        ];

        $itemsTotal = array_reduce(
            $lines,
            fn (Money $carry, DiscountLine $line): Money => $carry->plus($line->lineTotal),
            new Money(0, 'CZK'),
        );

        return new DiscountContext(
            lines: $lines,
            itemsTotal: $itemsTotal,
            couponCode: $code,
            customerId: $customerId,
            email: $email,
            shippingCost: new Money(9900, 'CZK'),
        );
    }

    private function engine(): DiscountEngine
    {
        return app(DiscountEngine::class);
    }

    public function test_a_percent_coupon_takes_its_share_of_every_line(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('SLEVA10')->percent(100)->create(['name' => 'Sleva 10 %']);

            $applied = $this->engine()->apply($this->context(code: 'SLEVA10'));

            $this->assertSame(10000, $applied->total->amount);
            $this->assertSame(6000, $applied->perLine[1]->amount);
            $this->assertSame(4000, $applied->perLine[2]->amount);
            $this->assertNull($applied->rejection);
            $this->assertCount(1, $applied->sources);
            $this->assertSame('SLEVA10', $applied->sources[0]->code);
        });
    }

    public function test_a_fixed_coupon_never_exceeds_the_basket(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('MEGA')->fixed(500000)->create(['name' => 'Sleva 5000 Kč']);

            $applied = $this->engine()->apply($this->context(code: 'MEGA'));

            $this->assertSame(100000, $applied->total->amount);
            $this->assertSame(100000, array_sum(array_map(fn (Money $m): int => $m->amount, $applied->perLine)));
        });
    }

    public function test_a_category_scoped_discount_only_touches_its_lines(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('KAT')->percent(200)->create([
                'name' => 'Sleva 20 % na kategorii',
                'scope' => Discount::SCOPE_CATEGORIES,
            ]);

            $discount->targets()->create([
                'target_type' => DiscountTarget::TYPE_CATEGORY,
                'target_id' => 7,
            ]);

            $applied = $this->engine()->apply($this->context(code: 'KAT'));

            $this->assertSame(12000, $applied->total->amount);
            $this->assertSame(12000, $applied->perLine[1]->amount);
            $this->assertArrayNotHasKey(2, $applied->perLine);
        });
    }

    public function test_a_scoped_discount_with_no_matching_line_is_rejected(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('KAT')->percent(200)->create([
                'scope' => Discount::SCOPE_CATEGORIES,
            ]);

            $discount->targets()->create([
                'target_type' => DiscountTarget::TYPE_CATEGORY,
                'target_id' => 999,
            ]);

            $applied = $this->engine()->apply($this->context(code: 'KAT'));

            $this->assertTrue($applied->total->isZero());
            $this->assertSame(DiscountRejection::NO_ELIGIBLE_ITEMS, $applied->rejection?->reason);
        });
    }

    public function test_an_expired_coupon_is_rejected_with_a_reason(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('STARY')->percent(100)->create([
                'ends_at' => now()->subDay(),
            ]);

            $applied = $this->engine()->apply($this->context(code: 'STARY'));

            $this->assertTrue($applied->total->isZero());
            $this->assertSame(DiscountRejection::EXPIRED, $applied->rejection?->reason);
        });
    }

    public function test_an_unknown_code_is_rejected(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $applied = $this->engine()->apply($this->context(code: 'NEEXISTUJE'));

            $this->assertSame(DiscountRejection::NOT_FOUND, $applied->rejection?->reason);
        });
    }

    public function test_a_min_cart_total_gates_the_coupon(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('NAD2000')->fixed(20000)->create([
                'min_cart_total' => 200000,
            ]);

            $applied = $this->engine()->apply($this->context(code: 'NAD2000'));

            $this->assertSame(DiscountRejection::MIN_CART, $applied->rejection?->reason);
        });
    }

    public function test_a_login_only_coupon_rejects_a_guest(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('PRIHLASENI')->percent(100)->create([
                'requires_login' => true,
            ]);

            $applied = $this->engine()->apply($this->context(code: 'PRIHLASENI'));

            $this->assertSame(DiscountRejection::REQUIRES_LOGIN, $applied->rejection?->reason);
        });
    }

    public function test_an_automatic_rule_applies_without_a_code(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->freeShipping()->create([
                'name' => 'Doprava zdarma nad 500 Kč',
                'min_cart_total' => 50000,
            ]);

            $applied = $this->engine()->apply($this->context());

            $this->assertTrue($applied->freeShipping);
            $this->assertTrue($applied->total->isZero());
            $this->assertCount(1, $applied->sources);
        });
    }

    public function test_a_non_combinable_rule_stands_down_for_a_coupon(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('SLEVA10')->percent(100)->create();
            Discount::factory()->percent(50)->create(['combinable' => false, 'name' => 'Automatická 5 %']);

            $applied = $this->engine()->apply($this->context(code: 'SLEVA10'));

            $this->assertSame(10000, $applied->total->amount);
            $this->assertCount(1, $applied->sources);
        });
    }

    public function test_a_combinable_rule_stacks_with_a_coupon(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('SLEVA10')->percent(100)->create();
            Discount::factory()->fixed(5000)->create(['combinable' => true, 'name' => 'Automatická 50 Kč']);

            $applied = $this->engine()->apply($this->context(code: 'SLEVA10'));

            $this->assertSame(15000, $applied->total->amount);
            $this->assertSame(15000, array_sum(array_map(fn (Money $m): int => $m->amount, $applied->perLine)));
            $this->assertCount(2, $applied->sources);
        });
    }

    public function test_stacking_discounts_are_capped_to_the_basket_total(): void
    {
        // Rule 6/8: a 90 % coupon plus a fixed rule that is itself already
        // capped to the basket (rule 5) still sum past itemsTotal (100 Kč
        // basket, 90 Kč + up to 1000 Kč) — the combined total must never
        // exceed what the shopper is buying, and both discounts still fire.
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Discount::factory()->code('SLEVA90')->percent(900)->create();
            Discount::factory()->fixed(500000)->create(['combinable' => true, 'name' => 'Automatická 5000 Kč']);

            $applied = $this->engine()->apply($this->context(code: 'SLEVA90'));

            $this->assertSame(100000, $applied->total->amount);
            $this->assertSame(100000, array_sum(array_map(fn (Money $m): int => $m->amount, $applied->perLine)));
            $this->assertCount(2, $applied->sources);
        });
    }

    public function test_a_first_order_only_coupon_rejects_a_returning_customer(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $email = 'vracena.zakaznice@example.com';

            DB::table('orders')->insert([
                'tenant_id' => $this->tenant->id,
                'uuid' => (string) Str::uuid(),
                'number' => '2026-0001',
                'checkout_token' => Str::random(32),
                'email' => $email,
                'billing' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Discount::factory()->code('PRVNI')->percent(100)->create([
                'first_order_only' => true,
            ]);

            $applied = $this->engine()->apply($this->context(code: 'PRVNI', email: $email));

            $this->assertSame(DiscountRejection::FIRST_ORDER_ONLY, $applied->rejection?->reason);
        });
    }

    public function test_a_deactivated_module_yields_nothing(): void
    {
        $other = Tenant::factory()->create(['name' => 'Shop Two']);

        app(TenantContext::class)->runAs($other, function (): void {
            $applied = $this->engine()->apply($this->context(code: 'SLEVA10'));

            $this->assertTrue($applied->total->isZero());
            $this->assertNull($applied->rejection);
        });
    }
}
