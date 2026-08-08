<?php

namespace Tests\Feature\Shop;

use App\Core\Shop\ShopClock;
use App\Core\Shop\ShopSettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The time zone and formats wave 3.6 stored and then never used (wave 3.7).
 */
class ShopClockTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);
    }

    private function save(array $data): void
    {
        app(ShopSettingsService::class)->update($data);
        app()->forgetScopedInstances();
    }

    private function clock(): ShopClock
    {
        return app(ShopClock::class);
    }

    /**
     * The reason this wave exists: an order placed late in the evening UTC
     * belongs to the next day in Prague, and the merchant reconciling their
     * day would find it missing.
     */
    public function test_a_late_evening_utc_moment_reads_as_the_next_day_in_prague(): void
    {
        $moment = Carbon::parse('2026-03-09 23:30:00', 'UTC');

        $this->save(['timezone' => 'Europe/Prague']);
        $this->assertSame('10. 3. 2026 00:30', $this->clock()->formatDateTime($moment));

        $this->save(['timezone' => 'UTC']);
        $this->assertSame('9. 3. 2026 23:30', $this->clock()->formatDateTime($moment));
    }

    public function test_the_chosen_date_format_is_used(): void
    {
        $moment = Carbon::parse('2026-03-09 12:00:00', 'UTC');

        $this->save(['timezone' => 'UTC', 'date_format' => 'd.m.Y']);
        $this->assertSame('09.03.2026', $this->clock()->formatDate($moment));

        $this->save(['timezone' => 'UTC', 'date_format' => 'Y-m-d']);
        $this->assertSame('2026-03-09', $this->clock()->formatDate($moment));
    }

    public function test_a_shop_that_set_nothing_gets_the_defaults(): void
    {
        $moment = Carbon::parse('2026-03-09 12:00:00', 'UTC');

        $this->assertSame('9. 3. 2026', $this->clock()->formatDate($moment));
    }

    public function test_null_stays_null(): void
    {
        $this->assertNull($this->clock()->formatDate(null));
        $this->assertNull($this->clock()->formatDateTime(null));
    }

    /**
     * Changing the display must not touch what is stored. Timestamps stay in
     * UTC; only the reading of them moves.
     */
    public function test_changing_the_timezone_does_not_rewrite_stored_data(): void
    {
        $before = $this->tenant->fresh()->created_at->toIso8601String();

        $this->save(['timezone' => 'Europe/Prague']);

        $this->assertSame($before, $this->tenant->fresh()->created_at->toIso8601String());
    }

    /**
     * A DATE column is not an instant. Shifting a taxable date to the day
     * before is not a display detail on a tax document — it is a different
     * tax period.
     */
    public function test_a_calendar_date_is_never_shifted_by_the_timezone(): void
    {
        $this->save(['timezone' => 'UTC', 'date_format' => 'd.m.Y']);

        $duzp = Carbon::parse('2026-03-09 00:00:00', 'UTC');

        $this->assertSame('09.03.2026', $this->clock()->formatCalendarDate($duzp));

        $this->save(['timezone' => 'Europe/Prague', 'date_format' => 'd.m.Y']);

        $this->assertSame('09.03.2026', $this->clock()->formatCalendarDate($duzp));
        $this->assertNull($this->clock()->formatCalendarDate(null));
    }

    /**
     * Without a tenant — the platform host, a console command — this still has
     * to answer rather than throw. It is reached from shared layouts.
     */
    public function test_it_works_without_a_tenant(): void
    {
        app(TenantContext::class)->forget();
        app()->forgetScopedInstances();

        $this->assertSame('9. 3. 2026', app(ShopClock::class)->formatDate(
            Carbon::parse('2026-03-09 12:00:00', 'UTC')
        ));
    }
}
