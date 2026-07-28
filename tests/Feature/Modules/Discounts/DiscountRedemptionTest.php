<?php

namespace Tests\Feature\Modules\Discounts;

use App\Core\Discounts\Contracts\DiscountRedemption as DiscountRedemptionContract;
use App\Core\Discounts\Exceptions\DiscountNoLongerValid;
use App\Core\Money\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Discounts\Models\Discount;
use Modules\Discounts\Models\DiscountRedemption;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The allowance bookkeeping itself, called directly.
 *
 * OrderDiscountTest drives this service through a real placement, but its two
 * refusal scenarios bind a stub that always throws — so they prove the caller
 * handles a refusal, not that this class ever produces one. Every branch here
 * is exercised against the real implementation: both throws, the vanished
 * discount, and the release path Task 9 depends on.
 */
class DiscountRedemptionTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create(['name' => 'Shop One']);

        $this->activateModule($this->tenant, 'discounts');
    }

    private function redemptions(): DiscountRedemptionContract
    {
        return app(DiscountRedemptionContract::class);
    }

    private function czk(int $amount): Money
    {
        return new Money($amount, 'CZK');
    }

    public function test_it_records_the_redemption_and_raises_the_counter(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('SLEVA')->percent(100)->create(['usage_limit' => 3]);

            $this->redemptions()->redeem((int) $discount->id, 55, ' Kupujici@Example.COM ', 7, $this->czk(10000));

            $row = DiscountRedemption::query()->firstOrFail();

            // Trimmed and lowercased on the way in, so the per-e-mail limit
            // can compare addresses without caring how they were typed.
            $this->assertSame('kupujici@example.com', $row->email);
            $this->assertSame(55, (int) $row->order_id);
            $this->assertSame(7, (int) $row->customer_id);
            $this->assertSame(10000, (int) $row->amount);
            $this->assertNull($row->released_at);
            $this->assertSame(1, (int) $discount->fresh()->used_count);
        });
    }

    public function test_an_exhausted_usage_limit_refuses(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('JEDEN')->percent(100)->create([
                'usage_limit' => 1,
                'used_count' => 1,
            ]);

            try {
                $this->redemptions()->redeem((int) $discount->id, 60, 'kdokoli@example.com', null, $this->czk(10000));
                $this->fail('An exhausted usage_limit must refuse.');
            } catch (DiscountNoLongerValid $e) {
                $this->assertSame('Slevový kód JEDEN už není platný.', $e->getMessage());
            }

            $this->assertSame(0, DiscountRedemption::query()->count());
            $this->assertSame(1, (int) $discount->fresh()->used_count);
        });
    }

    public function test_a_live_redemption_for_the_same_address_refuses_regardless_of_case(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('UVITACI')->percent(100)->create([
                'usage_limit_per_email' => 1,
            ]);

            $this->redemptions()->redeem((int) $discount->id, 61, 'jana@example.cz', null, $this->czk(10000));

            $this->expectException(DiscountNoLongerValid::class);
            $this->redemptions()->redeem((int) $discount->id, 62, 'JANA@Example.CZ', null, $this->czk(10000));
        });
    }

    public function test_a_released_row_stops_counting_toward_the_email_limit(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('UVITACI')->percent(100)->create([
                'usage_limit_per_email' => 1,
            ]);

            $this->redemptions()->redeem((int) $discount->id, 63, 'jana@example.cz', null, $this->czk(10000));
            $this->redemptions()->release(63);

            // The whole point of releasing: the address may buy again.
            $this->redemptions()->redeem((int) $discount->id, 64, 'jana@example.cz', null, $this->czk(10000));

            $this->assertSame(1, DiscountRedemption::query()->whereNull('released_at')->count());
            $this->assertSame(1, (int) $discount->fresh()->used_count);
        });
    }

    public function test_a_discount_deleted_before_the_redemption_is_a_no_op(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('ZMIZELA')->percent(100)->create();
            $id = (int) $discount->id;
            $discount->delete();

            // No throw: the order keeps the price it was already given rather
            // than being refused over a counter the shop itself removed.
            $this->redemptions()->redeem($id, 65, 'jana@example.cz', null, $this->czk(10000));

            $this->assertSame(0, DiscountRedemption::query()->count());
        });
    }

    public function test_release_stamps_the_row_and_lowers_the_counter_without_underflowing(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $discount = Discount::factory()->code('SLEVA')->percent(100)->create(['usage_limit' => 2]);

            $this->redemptions()->redeem((int) $discount->id, 70, 'jana@example.cz', null, $this->czk(10000));
            $this->assertSame(1, (int) $discount->fresh()->used_count);

            $this->redemptions()->release(70);

            $this->assertNotNull(DiscountRedemption::query()->firstOrFail()->released_at);
            $this->assertSame(0, (int) $discount->fresh()->used_count);

            // Idempotent, and the unsigned counter never wraps: a second
            // release finds no live row and leaves the counter at zero.
            $this->redemptions()->release(70);

            $this->assertSame(0, (int) $discount->fresh()->used_count);
            $this->assertSame(1, DiscountRedemption::query()->count());
        });
    }

    public function test_releasing_an_order_that_redeemed_nothing_is_a_no_op(): void
    {
        $this->context->runAs($this->tenant, function (): void {
            $this->redemptions()->release(999);

            $this->assertSame(0, DiscountRedemption::query()->count());
        });
    }
}
