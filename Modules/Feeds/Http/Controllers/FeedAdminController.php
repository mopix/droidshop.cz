<?php

namespace Modules\Feeds\Http\Controllers;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Categories\Models\Category;
use Modules\Feeds\Http\Requests\UpdateFeedRequest;
use Modules\Feeds\Models\FeedCategoryMapping;
use Modules\Feeds\Models\ProductFeed;

class FeedAdminController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Generations $generations,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('feeds.manage'), 403);

        $feeds = ProductFeed::query()->get()->keyBy('type');
        $mappings = FeedCategoryMapping::query()->get();

        return Inertia::render('Modules/Feeds/Index', [
            'feeds' => collect(ProductFeed::TYPES)->map(fn (string $type) => [
                'type' => $type,
                'enabled' => (bool) ($feeds[$type]->enabled ?? false),
                'delivery_date' => $feeds->get($type)?->deliveryDays() ?? 7,
                'url' => url('/feed/'.$type.'.xml'),
            ])->values(),
            'categories' => Category::query()->orderBy('path')->orderBy('position')->get()
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'depth' => $category->depth,
                    'mapping' => collect(ProductFeed::TYPES)->mapWithKeys(fn (string $type) => [
                        $type => (string) $mappings
                            ->first(fn (FeedCategoryMapping $row) => $row->category_id === $category->id
                                && $row->type === $type)
                            ?->category_text,
                    ]),
                ]),
        ]);
    }

    public function update(UpdateFeedRequest $request, string $type): RedirectResponse
    {
        abort_unless(in_array($type, ProductFeed::TYPES, true), 404);

        $feed = ProductFeed::query()->firstOrNew(['type' => $type]);
        $feed->enabled = $request->boolean('enabled');
        $feed->settings = ['delivery_date' => (int) ($request->validated('delivery_date') ?? 7)];
        $feed->save();

        $this->forgetCache($type);

        return back()->with('status', 'Nastavení feedu bylo uloženo.');
    }

    public function categories(Request $request, string $type): RedirectResponse
    {
        abort_unless($request->user()?->can('feeds.manage'), 403);
        abort_unless(in_array($type, ProductFeed::TYPES, true), 404);

        $validated = $request->validate([
            'mappings' => ['array'],
            'mappings.*.category_id' => ['required', 'integer'],
            'mappings.*.category_text' => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($validated['mappings'] ?? [] as $row) {
            // Resolved through the tenant-scoped model, so a category id from
            // another shop simply does not come back and is dropped.
            $category = Category::query()->find($row['category_id']);

            if ($category === null) {
                continue;
            }

            $text = trim((string) ($row['category_text'] ?? ''));

            if ($text === '') {
                FeedCategoryMapping::query()
                    ->where('category_id', $category->id)
                    ->where('type', $type)
                    ->delete();

                continue;
            }

            FeedCategoryMapping::query()->updateOrCreate(
                ['category_id' => $category->id, 'type' => $type],
                ['category_text' => $text],
            );
        }

        $this->forgetCache($type);

        return back()->with('status', 'Mapování kategorií bylo uloženo.');
    }

    /**
     * A merchant who just fixed a category must not wait an hour to see it in
     * the feed; the TTL is there for crawler traffic, not for the admin.
     *
     * The feed's cache key now carries the tenant's catalogue generation
     * (wave 3.0), so the plain `Cache::forget('feed:'.$tenant.':'.$type)`
     * this used to do can no longer match anything — the key it built never
     * had a generation stamp in it. Bumping the same Catalog generation the
     * feed itself reads is what "invalidate the feed now" means under the
     * new scheme: the next request misses the old, now-orphaned key and
     * rebuilds. `ProductFeed`/`FeedCategoryMapping` are not in
     * PageCacheObserver's model map (they never render onto a storefront
     * page), so nothing else bumps this on their behalf — it has to happen
     * here. The unused `$type` parameter stays: a future per-type dimension
     * would slot in without touching the call sites.
     */
    private function forgetCache(string $type): void
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            return;
        }

        $this->generations->bump($tenant, Dimension::Catalog);
    }
}
