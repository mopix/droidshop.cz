<?php

namespace Tests\Feature\Modules\Reviews;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Services\ReviewAggregator;
use Tests\TestCase;

class ReviewAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        app(TenantContext::class)->set($this->tenant);
    }

    private function review(int $rating, string $status, int $productId = 7, int $orderId = 1): Review
    {
        return Review::factory()->create([
            'tenant_id' => $this->tenant->id,
            'subject' => Review::SUBJECT_PRODUCT,
            'product_id' => $productId,
            'order_id' => $orderId,
            'rating' => $rating,
            'status' => $status,
        ]);
    }

    public function test_only_published_reviews_count(): void
    {
        $this->review(5, Review::STATUS_PUBLISHED, orderId: 1);
        $this->review(1, Review::STATUS_PENDING, orderId: 2);
        $this->review(1, Review::STATUS_REJECTED, orderId: 3);

        $aggregate = app(ReviewAggregator::class)->recalculate(7);

        $this->assertSame(1, $aggregate->rating_count);
        $this->assertSame('5.0', (string) $aggregate->rating_avg);
    }

    public function test_average_and_breakdown_match_a_direct_count(): void
    {
        $this->review(5, Review::STATUS_PUBLISHED, orderId: 1);
        $this->review(4, Review::STATUS_PUBLISHED, orderId: 2);
        $this->review(2, Review::STATUS_PUBLISHED, orderId: 3);

        $aggregate = app(ReviewAggregator::class)->recalculate(7);

        // (5+4+2)/3 = 3.666… — one decimal, rounded half up.
        $this->assertSame('3.7', (string) $aggregate->rating_avg);
        $this->assertSame(3, $aggregate->rating_count);
        $this->assertSame(1, $aggregate->count_5);
        $this->assertSame(1, $aggregate->count_4);
        $this->assertSame(0, $aggregate->count_3);
        $this->assertSame(1, $aggregate->count_2);
        $this->assertSame(0, $aggregate->count_1);
    }

    public function test_hiding_the_last_review_returns_the_aggregate_to_zero(): void
    {
        $review = $this->review(5, Review::STATUS_PUBLISHED);
        app(ReviewAggregator::class)->recalculate(7);

        $review->update(['status' => Review::STATUS_REJECTED]);
        $aggregate = app(ReviewAggregator::class)->recalculate(7);

        $this->assertSame(0, $aggregate->rating_count);
        $this->assertSame('0.0', (string) $aggregate->rating_avg);
    }

    public function test_a_second_tenant_does_not_leak_into_the_average(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $this->review(5, Review::STATUS_PUBLISHED, orderId: 1);

        // Not withoutGlobalScopes()->create(): BelongsToTenant stamps
        // tenant_id from the ambient context regardless of what you pass, so
        // that row would land on THIS tenant and the test would pass while
        // proving nothing. runAs() is the only way to write for another shop.
        app(TenantContext::class)->runAs($other, fn () => Review::query()->create([
            'subject' => Review::SUBJECT_PRODUCT,
            'product_id' => 7,
            'order_id' => 99,
            'author_name' => 'Cizí',
            'author_email' => 'cizi@example.com',
            'rating' => 1,
            'status' => Review::STATUS_PUBLISHED,
        ]));

        $aggregate = app(ReviewAggregator::class)->recalculate(7);

        $this->assertSame(1, $aggregate->rating_count);
        $this->assertSame('5.0', (string) $aggregate->rating_avg);
    }
}
