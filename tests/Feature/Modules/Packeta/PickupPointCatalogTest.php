<?php

namespace Tests\Feature\Modules\Packeta;

use App\Core\Shipping\Contracts\PickupPointCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Packeta\Models\PickupPoint;
use Tests\TestCase;

class PickupPointCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '1001', 'name' => 'Žabovřesky — Večerka',
            'street' => 'Horova 1', 'city' => 'Brno', 'zip' => '61600', 'country' => 'CZ',
            'search_text' => PickupPoint::normalise('Žabovřesky — Večerka Horova 1 Brno 61600'),
            'is_active' => true,
        ]);

        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '1002', 'name' => 'Praha — Trafika',
            'street' => 'Vinohradská 5', 'city' => 'Praha', 'zip' => '13000', 'country' => 'CZ',
            'search_text' => PickupPoint::normalise('Praha — Trafika Vinohradská 5 Praha 13000'),
            'is_active' => false,
        ]);
    }

    public function test_search_matches_without_diacritics(): void
    {
        $catalog = $this->app->make(PickupPointCatalog::class);

        $hit = $catalog->search('packeta', 'zabovresky');

        $this->assertCount(1, $hit);
        $this->assertSame('1001', $hit->first()->pointCode());
    }

    public function test_search_matches_by_zip(): void
    {
        $this->assertCount(1, $this->app->make(PickupPointCatalog::class)->search('packeta', '61600'));
    }

    public function test_search_skips_inactive_points(): void
    {
        $this->assertCount(0, $this->app->make(PickupPointCatalog::class)->search('packeta', 'Trafika'));
    }

    public function test_find_returns_null_for_an_inactive_point(): void
    {
        $catalog = $this->app->make(PickupPointCatalog::class);

        $this->assertNotNull($catalog->find('packeta', '1001'));
        $this->assertNull($catalog->find('packeta', '1002'));
        $this->assertNull($catalog->find('packeta', 'nope'));
    }

    /**
     * Two carriers can both have a point matching the same free-text term —
     * `search()` must scope to the requested carrier the same way `find()`
     * already does, or a shop offering a second carrier would leak the
     * other's branches into its picker (wave 3.10 prep).
     */
    public function test_search_does_not_return_another_carriers_point(): void
    {
        PickupPoint::create([
            'carrier' => 'packeta', 'code' => '2001', 'name' => 'Testovice — Packeta',
            'street' => 'Ulice 1', 'city' => 'Testovice', 'zip' => '10000', 'country' => 'CZ',
            'search_text' => PickupPoint::normalise('Testovice Packeta Ulice 1 Testovice 10000'),
            'is_active' => true,
        ]);

        PickupPoint::create([
            'carrier' => 'other', 'code' => '3001', 'name' => 'Testovice — Other',
            'street' => 'Ulice 2', 'city' => 'Testovice', 'zip' => '10000', 'country' => 'CZ',
            'search_text' => PickupPoint::normalise('Testovice Other Ulice 2 Testovice 10000'),
            'is_active' => true,
        ]);

        $found = $this->app->make(PickupPointCatalog::class)->search('packeta', 'Testovice');

        $this->assertCount(1, $found);
        $this->assertSame('packeta', $found->first()->pointCarrier());
        $this->assertSame('2001', $found->first()->pointCode());
    }
}
