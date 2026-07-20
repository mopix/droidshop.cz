<?php

namespace Modules\Products\Services;

use App\Core\Html\HtmlSanitizer;
use App\Core\Routing\RedirectRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Manufacturer;
use Modules\Products\Models\Product;

/**
 * Every write to a product goes through here.
 *
 * Three things must not depend on a controller remembering them: descriptions
 * are sanitised, slugs stay unique per shop, and a changed slug leaves a 301
 * behind.
 */
class ProductWriter
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly RedirectRegistry $redirects,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Product
    {
        $attributes = $this->prepare($attributes);
        $attributes['slug'] ??= $this->uniqueSlug($attributes['name']);

        return Product::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes): Product
    {
        $oldSlug = $product->slug;
        $attributes = $this->prepare($attributes);

        if (isset($attributes['slug'])) {
            $attributes['slug'] = $this->uniqueSlug($attributes['slug'], $product->id);
        }

        $product->fill($attributes)->save();

        if ($product->slug !== $oldSlug) {
            $this->redirects->record(
                '/produkt/'.$oldSlug,
                '/produkt/'.$product->slug,
                'product.slug',
            );
        }

        return $product;
    }

    /**
     * @param  list<int>  $categoryIds
     */
    public function syncCategories(Product $product, array $categoryIds, ?int $primaryId): void
    {
        // Resolved through the tenant-scoped model, so an id from another shop
        // simply does not come back and is silently dropped rather than
        // attached.
        $ids = Category::query()->whereIn('id', $categoryIds)->pluck('id')->all();

        if ($primaryId !== null && ! in_array($primaryId, $ids, true)) {
            $primaryId = null;
        }

        $primaryId ??= $ids[0] ?? null;

        $product->categories()->sync(
            collect($ids)
                ->mapWithKeys(fn (int $id) => [$id => [
                    'tenant_id' => $product->tenant_id,
                    'is_primary' => $id === $primaryId,
                ]])
                ->all()
        );
    }

    /**
     * Finds or creates a manufacturer by name, matched on its slug.
     *
     * Matching on the slug rather than the string means "Acme" and "acme"
     * are one manufacturer, which is what a CSV import will hand us.
     */
    public function manufacturer(string $name): Manufacturer
    {
        return Manufacturer::query()->firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name],
        );
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            // Soft delete: orders hold a snapshot but the foreign key has to
            // stay valid (spec §16.1).
            $product->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepare(array $attributes): array
    {
        if (array_key_exists('description', $attributes)) {
            $attributes['description'] = $this->sanitizer->clean($attributes['description']);
        }

        return $attributes;
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug($source), 185, '');
        $slug = $base;
        $suffix = 1;

        while ($this->slugTaken($slug, $ignoreId)) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        return Product::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }
}
