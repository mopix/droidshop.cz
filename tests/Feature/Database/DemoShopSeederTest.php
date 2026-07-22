<?php

namespace Tests\Feature\Database;

use App\Models\Tenant;
use Database\Seeders\DemoShopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoShopSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_creates_demo_tenant(): void
    {
        $this->artisan('modules:sync');
        $this->seed(DemoShopSeeder::class);
        $this->seed(DemoShopSeeder::class); // second run must not duplicate

        $this->assertSame(1, Tenant::where('name', 'Demo obchod')->count());
    }
}
