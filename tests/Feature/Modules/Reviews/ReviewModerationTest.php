<?php

namespace Tests\Feature\Modules\Reviews;

use App\Core\PageCache\Dimension;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Services\ReviewModerator;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The moderation queue (wave 4.0, task 5).
 *
 * Every state change goes through ReviewModerator rather than the model, so
 * the aggregate and the page cache generation cannot drift away from the row.
 */
class ReviewModerationTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'reviews');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);

        app(TenantContext::class)->set($this->tenant);
    }

    private function pending(int $rating = 2): Review
    {
        return Review::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_id' => 7,
            'order_id' => random_int(1, 100000),
            'rating' => $rating,
        ]);
    }

    public function test_publishing_updates_the_aggregate(): void
    {
        $review = $this->pending(4);

        app(ReviewModerator::class)->publish($review);

        $this->assertDatabaseHas('review_aggregates', [
            'tenant_id' => $this->tenant->id,
            'product_id' => 7,
            'rating_count' => 1,
        ]);
    }

    public function test_publishing_bumps_the_catalog_generation(): void
    {
        $before = $this->tenant->fresh()->{Dimension::Catalog->column()};

        app(ReviewModerator::class)->publish($this->pending());

        $this->assertNotSame($before, $this->tenant->fresh()->{Dimension::Catalog->column()});
    }

    public function test_rejecting_without_a_reason_is_impossible(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(ReviewModerator::class)->reject($this->pending(), '   ');
    }

    public function test_rejection_is_written_to_the_audit_log(): void
    {
        $review = $this->pending();

        app(ReviewModerator::class)->reject($review, 'Vulgární výraz.');

        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $this->tenant->id,
            'action' => 'reviews.reject',
        ]);
    }

    public function test_hiding_a_published_review_drops_it_from_the_aggregate(): void
    {
        $review = $this->pending(5);
        $moderator = app(ReviewModerator::class);

        $moderator->publish($review);
        $moderator->hide($review->fresh(), 'Osobní údaje v textu.');

        $this->assertDatabaseHas('review_aggregates', [
            'tenant_id' => $this->tenant->id,
            'product_id' => 7,
            'rating_count' => 0,
        ]);
    }

    public function test_the_queue_only_shows_this_tenants_reviews(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();

        // runAs(), not withoutGlobalScopes(): see ReviewAggregatorTest — the
        // trait stamps tenant_id from context and would put this row on the
        // tenant under test, leaving the assertion below unable to fail.
        app(TenantContext::class)->runAs($other, fn () => Review::query()->create([
            'subject' => Review::SUBJECT_PRODUCT,
            'product_id' => 7,
            'order_id' => 4242,
            'author_name' => 'Cizí',
            'author_email' => 'cizi@example.com',
            'rating' => 1,
            'status' => Review::STATUS_PENDING,
        ]));

        $this->pending();

        $response = $this->actingAs($this->owner)
            ->get('http://shop1.droidshop/admin/m/reviews');

        $response->assertOk();
        $response->assertDontSee('cizi@example.com');
    }
}
