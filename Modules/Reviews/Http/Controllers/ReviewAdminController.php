<?php

namespace Modules\Reviews\Http\Controllers;

use App\Core\Catalog\Contracts\ProductCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Modules\Reviews\Http\Requests\ModerateReviewRequest;
use Modules\Reviews\Http\Requests\ReplyToReviewRequest;
use Modules\Reviews\Models\Review;
use Modules\Reviews\Services\ReviewModerator;

/**
 * The nájemce's moderation queue.
 *
 * Nothing here writes to a review directly — every action goes through
 * ReviewModerator, which is what keeps the aggregate and the page cache
 * generation in step with the row (see its docblock).
 */
class ReviewAdminController
{
    private const PER_PAGE = 25;

    private const STATUSES = [
        Review::STATUS_PENDING,
        Review::STATUS_PUBLISHED,
        Review::STATUS_REJECTED,
    ];

    public function __construct(
        private readonly ReviewModerator $moderator,
        private readonly ProductCatalog $catalog,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user('web')?->can('reviews.moderate'), 403);

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
        ]);

        $status = $filters['status'] ?? Review::STATUS_PENDING;

        $reviews = Review::query()
            ->where('status', $status)
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return inertia('Modules/Reviews/Index', [
            'reviews' => $reviews->through(fn (Review $review) => $this->present($review)),
            'filters' => ['status' => $status],
            'counts' => $this->counts(),
        ]);
    }

    public function publish(Request $request, Review $review): RedirectResponse
    {
        abort_unless((bool) $request->user('web')?->can('reviews.moderate'), 403);

        $this->moderator->publish($review);

        return back()->with('success', 'Recenze byla zveřejněna.');
    }

    public function reject(ModerateReviewRequest $request, Review $review): RedirectResponse
    {
        $this->moderator->reject($review, (string) $request->validated('reason'));

        return back()->with('success', 'Recenze byla zamítnuta.');
    }

    public function hide(ModerateReviewRequest $request, Review $review): RedirectResponse
    {
        $this->moderator->hide($review, (string) $request->validated('reason'));

        return back()->with('success', 'Recenze byla skryta.');
    }

    public function reply(ReplyToReviewRequest $request, Review $review): RedirectResponse
    {
        $this->moderator->reply($review, (string) $request->validated('body'));

        return back()->with('success', 'Odpověď byla uložena.');
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = Review::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $result = [];

        foreach (self::STATUSES as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Review $review): array
    {
        return [
            'id' => $review->id,
            'subject' => $review->subject,
            'product' => $this->productName($review),
            'author_name' => $review->author_name,
            'author_email' => $review->author_email,
            'rating' => $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'status' => $review->status,
            'rejection_reason' => $review->rejection_reason,
            'reply_body' => $review->reply_body,
            'created_at' => $review->created_at?->toIso8601String(),
            'published_at' => $review->published_at?->toIso8601String(),
        ];
    }

    /**
     * The reviews module never touches the products tables — it asks the
     * kernel contract, exactly as the storefront form does. A product hidden
     * or deleted since the order was placed simply has no name here.
     */
    private function productName(Review $review): ?string
    {
        if ($review->subject === Review::SUBJECT_SHOP) {
            return null;
        }

        return $this->catalog->findById((int) $review->product_id)?->catalogName();
    }
}
