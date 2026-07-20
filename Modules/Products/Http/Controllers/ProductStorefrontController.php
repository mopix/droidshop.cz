<?php

namespace Modules\Products\Http\Controllers;

use App\Core\Storage\FileStorage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Modules\Products\Models\Product;
use Modules\Storefront\Support\Seo;

/**
 * Public product detail (spec §16.1).
 */
class ProductStorefrontController
{
    public function __construct(private readonly FileStorage $files) {}

    public function __invoke(string $slug): View|Response
    {
        $product = Product::query()
            ->withTrashed()
            ->with(['images', 'taxRate', 'categories'])
            ->where('slug', $slug)
            ->first();

        // A product that was withdrawn is gone, not missing: 410 tells a
        // crawler to drop the URL instead of retrying it for months (§16.1).
        if ($product !== null && $product->trashed()) {
            return $this->gone($product);
        }

        // Drafts and hidden products are not "not published yet" to a visitor,
        // they simply do not exist. Anything else leaks the shop's pipeline.
        if ($product === null || $product->status !== Product::STATUS_ACTIVE) {
            abort(404);
        }

        $category = $product->primaryCategory();

        return view('products::storefront.show', [
            'product' => $product,
            'category' => $category,
            'images' => $product->images,
            'seo' => new Seo(
                title: $product->seo_title ?: $product->name,
                description: $product->seo_description ?: $product->short_description,
                canonical: Seo::canonicalFor($product->url()),
                image: $this->seoImage($product),
                type: 'product',
            ),
        ]);
    }

    private function gone(Product $product): Response
    {
        $category = $product->primaryCategory();

        return response()->view('storefront::shop-error', [
            'heading' => 'Produkt už není v nabídce',
            'message' => 'Tento produkt jsme z nabídky stáhli.',
            'backUrl' => $category?->url() ?? '/',
            'backLabel' => $category ? 'Přejít do kategorie '.$category->name : 'Zpět na úvod',
            'seo' => new Seo(title: 'Produkt už není v nabídce', noindex: true),
        ], 410);
    }

    private function seoImage(Product $product): ?string
    {
        $path = $product->seo_image_path ?: $product->mainImage()?->path;

        return $path === null ? null : $this->files->publicUrl($path);
    }
}
