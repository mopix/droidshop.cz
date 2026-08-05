<?php

namespace Modules\Pages\Services;

use App\Core\Html\HtmlSanitizer;
use App\Core\Routing\RedirectRegistry;
use Illuminate\Support\Str;
use Modules\Pages\Models\Page;

/**
 * The only way a page is written.
 *
 * Same shape as ProductWriter, and for the same reasons: sanitising on write
 * rather than on render means the policy is decided once instead of at every
 * call site, and a renamed slug has to leave a 301 behind or the tenant loses
 * whatever link equity the old URL had — which for a page linked from every
 * invoice e-mail is not hypothetical.
 */
class PageWriter
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly RedirectRegistry $redirects,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Page
    {
        $attributes = $this->prepare($attributes);
        $attributes['slug'] = $this->uniqueSlug($attributes['slug'] ?? '', null);

        return Page::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Page $page, array $attributes): Page
    {
        $oldSlug = $page->slug;
        $attributes = $this->prepare($attributes);

        if (isset($attributes['slug'])) {
            $attributes['slug'] = $this->uniqueSlug($attributes['slug'], $page->id);
        }

        $page->fill($attributes)->save();

        if ($page->slug !== $oldSlug) {
            // Since wave 3.1 pages answer at /{slug}, so both sides of the
            // redirect are root paths.
            $this->redirects->record('/'.$oldSlug, '/'.$page->slug, 'page.slug');
        }

        return $page;
    }

    public function delete(Page $page): void
    {
        $page->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepare(array $attributes): array
    {
        // Sanitised on write, never on render (project convention). A tenant
        // pasting markup from Word must not be able to put a script tag on
        // their own storefront.
        if (array_key_exists('body', $attributes)) {
            $attributes['body'] = $this->sanitizer->clean($attributes['body']);
        }

        if (array_key_exists('slug', $attributes)) {
            $attributes['slug'] = Str::slug((string) $attributes['slug']);
        }

        return $attributes;
    }

    /**
     * Slugs are unique per shop. A collision gets a numeric suffix rather
     * than an error: the tenant asked for a page, not for a lecture about
     * naming.
     */
    private function uniqueSlug(string $slug, ?int $ignoreId): string
    {
        $base = Str::slug($slug) ?: 'stranka';
        $candidate = $base;
        $suffix = 2;

        while ($this->slugTaken($candidate, $ignoreId)) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        return Page::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
