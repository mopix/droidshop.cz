<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Shipping\Exceptions\CarrierError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Packeta\Models\PickupPoint;
use Modules\Packeta\Services\PickupPointSync;
use Tests\TestCase;

class PickupPointSyncTest extends TestCase
{
    use RefreshDatabase;

    private function feed(array $points): array
    {
        return ['data' => $points];
    }

    private function point(string $id, string $name, string $city): array
    {
        return [
            'id' => $id, 'name' => $name, 'city' => $city,
            'street' => 'Hlavní 1', 'zip' => '60200', 'country' => 'cz',
            'latitude' => '49.19', 'longitude' => '16.60',
        ];
    }

    public function test_sync_inserts_points_from_the_feed(): void
    {
        config(['packeta.feed_min_points' => 1]);

        Http::fake(['*' => Http::response($this->feed([
            $this->point('1', 'Večerka', 'Brno'),
            $this->point('2', 'Trafika', 'Praha'),
        ]))]);

        $result = $this->app->make(PickupPointSync::class)->run('test-key');

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, PickupPoint::where('is_active', true)->count());
    }

    public function test_points_missing_from_the_feed_are_deactivated_not_deleted(): void
    {
        config(['packeta.feed_min_points' => 1]);

        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '9', 'name' => 'Zrušená',
            'city' => 'Ostrava', 'zip' => '70200', 'country' => 'CZ',
            'search_text' => 'zrusena ostrava', 'is_active' => true,
        ]);

        Http::fake(['*' => Http::response($this->feed([$this->point('1', 'Večerka', 'Brno')]))]);

        $this->app->make(PickupPointSync::class)->run('test-key');

        $this->assertSame(1, PickupPoint::where('code', '9')->count());
        $this->assertFalse(PickupPoint::where('code', '9')->first()->is_active);
    }

    public function test_an_empty_feed_is_not_applied(): void
    {
        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '1', 'name' => 'Večerka',
            'city' => 'Brno', 'zip' => '60200', 'country' => 'CZ',
            'search_text' => 'vecerka brno', 'is_active' => true,
        ]);

        Http::fake(['*' => Http::response($this->feed([]))]);

        $this->expectException(CarrierError::class);

        try {
            $this->app->make(PickupPointSync::class)->run('test-key');
        } finally {
            // One bad response must never wipe every tenant's pickup points.
            $this->assertTrue(PickupPoint::where('code', '1')->first()->is_active);
        }
    }

    public function test_a_feed_with_enough_rows_but_no_usable_ids_is_not_applied(): void
    {
        // Deliberately not 1: a threshold of 1 would let a single malformed
        // row (0 usable ids) slip under the raw-count guard too, and this
        // test exists specifically to prove the *second* guard (on usable
        // ids, not raw row count) catches what the first one cannot.
        config(['packeta.feed_min_points' => 2]);

        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '1', 'name' => 'Večerka',
            'city' => 'Brno', 'zip' => '60200', 'country' => 'CZ',
            'search_text' => 'vecerka brno', 'is_active' => true,
        ]);

        // Enough rows to pass the raw-count guard, but none carries an `id`
        // — e.g. Zásilkovna renaming the field, or a partially corrupted
        // response. Every row is skipped by the upsert loop, so $seen stays
        // empty.
        $malformed = [
            ['name' => 'Trafika', 'city' => 'Praha', 'street' => 'Hlavní 1', 'zip' => '60200', 'country' => 'cz'],
            ['name' => 'Kiosek', 'city' => 'Ostrava', 'street' => 'Hlavní 1', 'zip' => '60200', 'country' => 'cz'],
        ];

        Http::fake(['*' => Http::response($this->feed($malformed))]);

        $this->expectException(CarrierError::class);

        try {
            $this->app->make(PickupPointSync::class)->run('test-key');
        } finally {
            // Without the fix, whereNotIn('code', []) degrades to `1 = 1`
            // and deactivates every active point — including this one.
            $this->assertTrue(PickupPoint::where('code', '1')->first()->is_active);
        }
    }

    public function test_search_text_is_normalised_on_write(): void
    {
        config(['packeta.feed_min_points' => 1]);

        Http::fake(['*' => Http::response($this->feed([$this->point('1', 'Žabovřesky', 'Brno')]))]);

        $this->app->make(PickupPointSync::class)->run('test-key');

        $this->assertStringContainsString('zabovresky', PickupPoint::first()->search_text);
    }
}
