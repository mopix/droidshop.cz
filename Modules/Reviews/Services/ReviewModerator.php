<?php

namespace Modules\Reviews\Services;

use App\Core\Html\HtmlSanitizer;
use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Core\Services\AuditLog;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Reviews\Models\Review;

/**
 * Every state change a review can undergo, in one place.
 *
 * Three things have to happen together on each of them — the row changes, the
 * aggregate is recomputed, and the page cache generation moves — and a call
 * site that does two of the three leaves the storefront showing a stale
 * average with no way to tell.
 *
 * A rejection reason is mandatory by design, not by politeness: selective
 * publication of favourable reviews is an unfair commercial practice under
 * the Omnibus rules, so the shop has to be able to say why each rejected
 * review was rejected.
 */
class ReviewModerator
{
    public function __construct(
        private readonly ReviewAggregator $aggregator,
        private readonly Generations $generations,
        private readonly AuditLog $audit,
        private readonly TenantContext $context,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    public function publish(Review $review): void
    {
        $this->transition($review, [
            'status' => Review::STATUS_PUBLISHED,
            'published_at' => now(),
            'rejection_reason' => null,
        ], 'reviews.publish');
    }

    public function reject(Review $review, string $reason): void
    {
        $this->transition($review, [
            'status' => Review::STATUS_REJECTED,
            'published_at' => null,
            'rejection_reason' => $this->requireReason($reason),
        ], 'reviews.reject');
    }

    /** Hiding is rejecting something that was already public; the audit trail keeps them apart. */
    public function hide(Review $review, string $reason): void
    {
        $this->transition($review, [
            'status' => Review::STATUS_REJECTED,
            'published_at' => null,
            'rejection_reason' => $this->requireReason($reason),
        ], 'reviews.hide');
    }

    public function reply(Review $review, string $body): void
    {
        $this->transition($review, [
            'reply_body' => $this->sanitizer->clean($body),
            'reply_at' => now(),
        ], 'reviews.reply');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Review $review, array $attributes, string $action): void
    {
        DB::transaction(function () use ($review, $attributes, $action): void {
            $review->update([
                ...$attributes,
                'moderated_by' => auth('web')->id(),
                'moderated_at' => now(),
            ]);

            $this->aggregator->recalculate((int) $review->product_id);

            $this->audit->log($action, $review, array_filter([
                'reason' => $attributes['rejection_reason'] ?? null,
            ]));
        });

        // Outside the transaction: the generation counter is what makes the
        // storefront re-render, and bumping it before the commit would let a
        // request rebuild the page from rows that are not there yet.
        $tenant = $this->context->current();

        if ($tenant !== null) {
            $this->generations->bump($tenant, Dimension::Catalog);
        }
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Zamítnutí recenze vyžaduje důvod.');
        }

        return $reason;
    }
}
