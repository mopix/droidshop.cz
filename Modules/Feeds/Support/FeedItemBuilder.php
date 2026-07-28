<?php

namespace Modules\Feeds\Support;

use App\Core\Storage\FileStorage;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImage;
use Modules\Products\Models\ProductVariant;

/**
 * The catalogue as feed items.
 *
 * A generator over lazy(): a shop with 20 000 products must not hold its whole
 * catalogue in memory to publish a feed (same stance as ProductCsvExporter).
 *
 * Prices come from the catalog contract, so a running sale (wave 2.7) is in
 * the feed automatically — a feed quoting a different price than the cart is
 * the fastest way to a penalty from the comparison shopper.
 */
class FeedItemBuilder
{
    private const DESCRIPTION_LIMIT = 2000;

    public function __construct(
        private readonly CategoryTextResolver $categories,
        private readonly FileStorage $storage,
    ) {}

    /**
     * @return iterable<FeedItem>
     */
    public function items(string $type, int $deliveryDays): iterable
    {
        foreach (Product::query()
            ->published()
            ->with(['variants.optionValues.option', 'categories', 'manufacturer', 'images'])
            ->orderBy('id')
            ->lazy(200) as $product) {
            $categoryText = $this->categories->for($product->primaryCategory(), $type);

            if (! $product->catalogHasVariants()) {
                yield $this->productItem($product, $categoryText, $deliveryDays);

                continue;
            }

            foreach ($product->variants->where('active', true) as $variant) {
                $variant->setRelation('product', $product);

                yield $this->variantItem($product, $variant, $categoryText, $deliveryDays);
            }
        }
    }

    private function productItem(Product $product, string $categoryText, int $deliveryDays): FeedItem
    {
        return new FeedItem(
            id: (string) $product->id,
            name: $product->name,
            description: $this->description($product),
            url: url($product->url()),
            imageUrl: $product->catalogImageUrl(),
            alternativeImageUrls: $this->alternativeImages($product),
            priceVat: $product->catalogPrice(),
            manufacturer: $product->manufacturer?->name,
            categoryText: $categoryText,
            ean: $product->ean,
            sku: $product->sku,
            deliveryDays: $product->catalogIsAvailable() ? 0 : $deliveryDays,
            itemGroupId: null,
            params: [],
        );
    }

    private function variantItem(
        Product $product,
        ProductVariant $variant,
        string $categoryText,
        int $deliveryDays,
    ): FeedItem {
        $params = [];

        foreach ($variant->optionValues as $value) {
            $params[$value->option->name] = $value->value;
        }

        return new FeedItem(
            // Prefixed rather than the bare variant id: ITEM_ID has to be
            // unique across the whole feed, and a product id could otherwise
            // collide with a variant id.
            id: $product->id.'-'.$variant->id,
            name: trim($product->name.' '.$variant->catalogVariantLabel()),
            description: $this->description($product),
            url: url($product->url()),
            imageUrl: $product->catalogImageUrl(),
            alternativeImageUrls: $this->alternativeImages($product),
            priceVat: $variant->catalogVariantPrice(),
            manufacturer: $product->manufacturer?->name,
            categoryText: $categoryText,
            ean: $variant->ean,
            sku: $variant->sku,
            deliveryDays: $variant->catalogVariantIsAvailable() ? 0 : $deliveryDays,
            itemGroupId: (string) $product->id,
            params: $params,
        );
    }

    /**
     * Plain text: both feeds expect a description without markup, and the
     * stored value is sanitised HTML from the admin editor.
     */
    private function description(Product $product): string
    {
        $html = (string) $product->description;

        // A space before stripping, or two paragraphs come out glued together
        // as "hliníkové tělo.Demo produkt" — the comparison shopper shows that
        // string to a customer verbatim.
        $text = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])[^>]*>/i', ' ', $html) ?? $html;
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        if ($text === '') {
            $text = trim((string) $product->short_description);
        }

        return mb_substr($text, 0, self::DESCRIPTION_LIMIT);
    }

    /**
     * @return list<string>
     */
    private function alternativeImages(Product $product): array
    {
        $main = $product->mainImage();

        return $product->images
            ->reject(fn (ProductImage $image) => $main !== null && $image->id === $main->id)
            ->map(fn (ProductImage $image) => $this->storage->publicUrl($image->path))
            ->values()
            ->all();
    }
}
