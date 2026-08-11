<?php

namespace Tests\Feature\Core;

use App\Core\Shipping\Contracts\CarrierRegistry;
use App\Core\Shipping\Contracts\PickupPointCatalog;
use App\Core\Shipping\Contracts\ShipmentBook;
use Tests\TestCase;

/**
 * The kernel must answer these three questions safely even when no carrier
 * module is deployed — a storefront on a shop without Packeta may not blow up
 * asking whether a delivery method needs a pickup point.
 */
class CarrierContractsTest extends TestCase
{
    public function test_registry_resolves_to_null_without_a_carrier_module(): void
    {
        $registry = $this->app->make(CarrierRegistry::class);

        $this->assertNull($registry->for('packeta'));
    }

    public function test_pickup_point_catalog_is_empty_without_a_carrier_module(): void
    {
        $catalog = $this->app->make(PickupPointCatalog::class);

        $this->assertTrue($catalog->search('packeta', 'Brno')->isEmpty());
        $this->assertNull($catalog->find('packeta', '12345'));
    }

    public function test_shipment_book_answers_null_without_a_carrier_module(): void
    {
        $book = $this->app->make(ShipmentBook::class);

        $this->assertNull($book->forOrder(1));
    }
}
