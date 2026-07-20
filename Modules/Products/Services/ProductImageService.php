<?php

namespace Modules\Products\Services;

use App\Core\Storage\FileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImage;

/**
 * Product images: upload, order, alt text, main image.
 *
 * Files go through FileStorage, never to a disk directly, so every path lands
 * under the tenant's own prefix and counts against their storage limit
 * (spec §15.1).
 *
 * Image cuts (thumb/list/detail, WebP) are a later wave; the original is
 * served for now.
 */
class ProductImageService
{
    /** 8 MB per file (spec §16.1). */
    public const MAX_KILOBYTES = 8192;

    /** @var list<string> extensions */
    public const ALLOWED_MIMES = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var list<string> the types the contents must actually be */
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly FileStorage $storage) {}

    public function add(Product $product, UploadedFile $file, ?string $alt = null): ProductImage
    {
        $this->validate($file);

        // The stored name is generated, never derived from the upload. A
        // client-supplied name is attacker-controlled: path traversal, double
        // extensions, and collisions all arrive that way.
        $path = sprintf(
            'products/%d/%s.%s',
            $product->id,
            Str::uuid(),
            strtolower($file->getClientOriginalExtension() ?: 'jpg'),
        );

        $this->storage->putPublic($path, file_get_contents($file->getRealPath()));

        return DB::transaction(function () use ($product, $path, $alt) {
            $isFirst = ! $product->images()->exists();

            return ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => $path,
                'alt' => $alt,
                'position' => ((int) $product->images()->max('position')) + 10,
                // A product whose images have no main one renders an empty
                // tile in every listing, so the first upload takes the slot.
                'is_main' => $isFirst,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ProductImage $image, array $attributes): ProductImage
    {
        $image->fill(array_intersect_key($attributes, array_flip(['alt'])))->save();

        return $image;
    }

    public function makeMain(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            ProductImage::query()
                ->where('product_id', $image->product_id)
                ->update(['is_main' => false]);

            $image->forceFill(['is_main' => true])->save();
        });
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorder(Product $product, array $orderedIds): void
    {
        DB::transaction(function () use ($product, $orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                ProductImage::query()
                    ->whereKey($id)
                    ->where('product_id', $product->id)
                    ->update(['position' => ($position + 1) * 10]);
            }
        });
    }

    public function remove(ProductImage $image): void
    {
        $wasMain = $image->is_main;
        $productId = $image->product_id;
        $path = $image->path;

        DB::transaction(function () use ($image, $wasMain, $productId) {
            $image->delete();

            if (! $wasMain) {
                return;
            }

            $next = ProductImage::query()
                ->where('product_id', $productId)
                ->orderBy('position')
                ->first();

            $next?->forceFill(['is_main' => true])->save();
        });

        // After the commit: a file deleted inside a transaction that then
        // rolls back is gone while the row that names it survives.
        $this->storage->delete($path, private: false);
    }

    public function removeAllFor(Product $product): void
    {
        foreach ($product->images()->get() as $image) {
            $this->remove($image);
        }
    }

    public function url(ProductImage $image): string
    {
        return $this->storage->publicUrl($image->path);
    }

    private function validate(UploadedFile $file): void
    {
        Validator::make(
            ['file' => $file],
            ['file' => [
                'required',
                'image',
                'mimes:'.implode(',', self::ALLOWED_MIMES),
                'mimetypes:'.implode(',', self::ALLOWED_MIME_TYPES),
                'max:'.self::MAX_KILOBYTES,
                // The rules above all reason about the declared type. This one
                // opens the file. An HTML document renamed to .jpg satisfies
                // every extension check and would then be served from the
                // shop's own origin — same-origin script execution, handed out
                // by an upload form.
                function (string $attribute, mixed $value, callable $fail) {
                    if (@getimagesize($value->getRealPath()) === false) {
                        $fail('Soubor není platný obrázek.');
                    }
                },
            ]],
            ['file.max' => 'Obrázek smí mít nejvýše 8 MB.'],
        )->validate();
    }
}
