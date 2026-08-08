<?php

namespace Tests\Feature\Platform;

use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Domain;
use App\Models\PlatformAdmin;
use App\Models\TaxRate;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActsAsPlatformAdmin;
use Tests\TestCase;

/**
 * VAT rates, managed by the platform (wave 3.7).
 *
 * Until now the table was seeded by its own migration and never touched
 * again, so following a change in the law meant writing another migration.
 */
class TaxRateAdminTest extends TestCase
{
    use ActsAsPlatformAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePlatformHost();
        $this->actingAsPlatformAdmin(PlatformAdmin::factory()->withTwoFactor()->create());
    }

    private function url(string $path = ''): string
    {
        return $this->platformUrl('/superadmin/dph'.$path);
    }

    public function test_the_screen_lists_the_seeded_rates(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Platform/TaxRates')->has('rates', 3));
    }

    public function test_a_rate_can_be_added(): void
    {
        $this->post($this->url(), [
            'code' => 'second_reduced',
            'name' => 'Druhá snížená 10 %',
            'percent' => 10,
            'position' => 25,
        ])->assertRedirect();

        $this->assertSame(100, TaxRate::query()->where('code', 'second_reduced')->value('rate_permille'));
    }

    /**
     * A rate like 12.5 % exists, which is why the column is per mille and not
     * a percentage — and why a float must not survive the trip.
     */
    public function test_a_fractional_rate_is_stored_exactly(): void
    {
        $this->post($this->url(), [
            'code' => 'half',
            'name' => 'Poloviční 12,5 %',
            'percent' => 12.5,
            'position' => 40,
        ])->assertRedirect();

        $this->assertSame(125, TaxRate::query()->where('code', 'half')->value('rate_permille'));
    }

    public function test_a_rate_above_a_hundred_percent_is_refused(): void
    {
        $this->post($this->url(), [
            'code' => 'absurd',
            'name' => 'Překlep',
            'percent' => 210,
            'position' => 50,
        ])->assertSessionHasErrors('percent');
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        $this->post($this->url(), [
            'code' => 'standard',
            'name' => 'Kolize',
            'percent' => 15,
            'position' => 60,
        ])->assertSessionHasErrors('code');
    }

    public function test_the_percentage_can_be_changed(): void
    {
        $rate = TaxRate::query()->where('code', 'standard')->firstOrFail();

        $this->patch($this->url('/'.$rate->id), [
            'code' => 'standard',
            'name' => 'Základní 19 %',
            'percent' => 19,
            'position' => $rate->position,
        ])->assertRedirect();

        $this->assertSame(190, $rate->fresh()->rate_permille);
    }

    /**
     * TaxRates caches the whole table for a day, on the grounds that rates
     * change by act of parliament. Without a flush the shops would keep
     * charging the old one until the cache expired.
     */
    public function test_a_change_invalidates_the_rate_cache(): void
    {
        $rates = app(TaxRates::class);
        $this->assertSame(210, $rates->find('standard')->rate_permille);

        $rate = TaxRate::query()->where('code', 'standard')->firstOrFail();

        $this->patch($this->url('/'.$rate->id), [
            'code' => 'standard',
            'name' => 'Základní 19 %',
            'percent' => 19,
            'position' => $rate->position,
        ]);

        $this->assertSame(190, app(TaxRates::class)->find('standard')->rate_permille);
    }

    public function test_exactly_one_rate_is_the_default(): void
    {
        $reduced = TaxRate::query()->where('code', 'reduced')->firstOrFail();

        $this->patch($this->url('/'.$reduced->id), [
            'code' => 'reduced',
            'name' => $reduced->name,
            'percent' => 12,
            'position' => $reduced->position,
            'is_default' => true,
        ])->assertRedirect();

        $this->assertTrue($reduced->fresh()->is_default);
        $this->assertSame(1, TaxRate::query()->where('is_default', true)->count());
    }

    public function test_an_unused_rate_can_be_deleted(): void
    {
        $zero = TaxRate::query()->where('code', 'zero')->firstOrFail();

        $this->delete($this->url('/'.$zero->id))->assertRedirect();

        $this->assertNull(TaxRate::find($zero->id));
    }

    /**
     * A deleted rate leaves an invoice nobody can reconstruct: the document
     * snapshots the percentage, but the product it was sold from points at a
     * row that is gone.
     */
    public function test_a_rate_in_use_cannot_be_deleted(): void
    {
        $rate = TaxRate::query()->where('code', 'zero')->firstOrFail();

        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->runAs($tenant, fn () => app(ProductWriter::class)->create([
            'name' => 'Kladivo',
            'sku' => 'KLADIVO',
            'price' => 1000,
            'status' => Product::STATUS_ACTIVE,
            'tax_rate_id' => $rate->id,
        ]));

        $this->usePlatformHost();

        $this->delete($this->url('/'.$rate->id))->assertSessionHasErrors('rate');

        $this->assertNotNull(TaxRate::find($rate->id));
    }

    public function test_the_default_rate_cannot_be_deleted(): void
    {
        $standard = TaxRate::query()->where('code', 'standard')->firstOrFail();

        $this->delete($this->url('/'.$standard->id))->assertSessionHasErrors('rate');

        $this->assertNotNull(TaxRate::find($standard->id));
    }

    /**
     * The console must not be reachable on a shop's domain (spec §15.4).
     */
    public function test_a_tenant_cannot_reach_it(): void
    {
        $tenant = Tenant::factory()->create();
        Domain::create([
            'tenant_id' => $tenant->id,
            'domain' => 'obchod.'.config('tenancy.platform_domain'),
            'type' => 'subdomain',
            'is_primary' => true,
        ]);

        $this->get('http://obchod.'.config('tenancy.platform_domain').'/superadmin/dph')
            ->assertNotFound();
    }
}
