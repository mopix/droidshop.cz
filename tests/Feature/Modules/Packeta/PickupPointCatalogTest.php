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

        $hit = $catalog->search('zabovresky');

        $this->assertCount(1, $hit);
        $this->assertSame('1001', $hit->first()->pointCode());
    }

    public function test_search_matches_by_zip(): void
    {
        $this->assertCount(1, $this->app->make(PickupPointCatalog::class)->search('61600'));
    }

    public function test_search_skips_inactive_points(): void
    {
        $this->assertCount(0, $this->app->make(PickupPointCatalog::class)->search('Trafika'));
    }

    public function test_find_returns_null_for_an_inactive_point(): void
    {
        $catalog = $this->app->make(PickupPointCatalog::class);

        $this->assertNotNull($catalog->find('packeta', '1001'));
        $this->assertNull($catalog->find('packeta', '1002'));
        $this->assertNull($catalog->find('packeta', 'nope'));
    }
}
