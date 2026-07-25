<?php

namespace Tests\Feature\Storefront;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;
use Tests\TestCase;

class HomepageSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_blocks_are_scoped_to_the_current_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        app(TenantContext::class)->runAs($a, fn () => HomepageBlock::create([
            'position' => 0, 'type' => BlockType::Text, 'payload' => ['html' => 'A'], 'visible' => true,
        ]));

        $seenByB = app(TenantContext::class)->runAs($b, fn () => HomepageBlock::count());
        $seenByA = app(TenantContext::class)->runAs($a, fn () => HomepageBlock::count());

        $this->assertSame(0, $seenByB);
        $this->assertSame(1, $seenByA);
    }
}
