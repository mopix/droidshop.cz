<?php

namespace Tests\Feature\Modules;

use App\Core\Storage\Exceptions\StorageLimitExceeded;
use App\Core\Storage\FileStorage;
use App\Core\Tax\TaxRates;
use App\Core\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImage;
use Modules\Products\Services\ProductImageService;
use Modules\Products\Services\ProductWriter;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private ProductImageService $images;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        Storage::fake(FileStorage::PUBLIC_DISK);
        Storage::fake(FileStorage::PRIVATE_DISK);

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->images = app(ProductImageService::class);
        $this->tenant = Tenant::factory()->create();

        $this->activateModule($this->tenant, 'products');
    }

    private function inShop(callable $callback): mixed
    {
        return $this->context->runAs($this->tenant, $callback);
    }

    private function product(): Product
    {
        return app(ProductWriter::class)->create([
            'name' => 'Notebook Acme 14',
            'price' => 24_990_00,
            'tax_rate_id' => app(TaxRates::class)->default()->id,
        ]);
    }

    public function test_an_uploaded_image_lands_under_the_tenants_prefix(): void
    {
        $this->inShop(function () {
            $product = $this->product();

            $image = $this->images->add($product, UploadedFile::fake()->image('foto.jpg'));

            Storage::disk(FileStorage::PUBLIC_DISK)
                ->assertExists("tenants/{$this->tenant->id}/".$image->path);
        });
    }

    public function test_the_stored_name_is_generated_not_taken_from_the_client(): void
    {
        // A client-supplied name is attacker-controlled: path traversal, a
        // double extension, or simply a collision between two shops' uploads.
        $this->inShop(function () {
            $product = $this->product();

            $image = $this->images->add(
                $product,
                UploadedFile::fake()->image('../../../etc/passwd.jpg')
            );

            $this->assertStringNotContainsString('..', $image->path);
            $this->assertStringNotContainsString('passwd', $image->path);
            $this->assertStringEndsWith('.jpg', $image->path);
        });
    }

    public function test_a_file_that_is_not_an_allowed_image_is_refused(): void
    {
        $this->inShop(function () {
            $product = $this->product();

            $this->expectException(ValidationException::class);

            $this->images->add($product, UploadedFile::fake()->create('sklad.pdf', 10, 'application/pdf'));
        });
    }

    public function test_a_file_pretending_to_be_an_image_by_extension_is_refused(): void
    {
        // The extension says jpg, the content does not. Trusting the extension
        // is how an HTML file ends up served from the shop's own origin.
        $this->inShop(function () {
            $product = $this->product();

            $this->expectException(ValidationException::class);

            $this->images->add(
                $product,
                UploadedFile::fake()->createWithContent('sklad.jpg', '<html>not an image</html>')
            );
        });
    }

    public function test_a_file_over_the_size_cap_is_refused(): void
    {
        $this->inShop(function () {
            $product = $this->product();

            $this->expectException(ValidationException::class);

            $this->images->add(
                $product,
                UploadedFile::fake()->image('velky.jpg')->size(ProductImageService::MAX_KILOBYTES + 1)
            );
        });
    }

    public function test_the_storage_limit_of_the_plan_still_applies(): void
    {
        $plan = Plan::factory()->create(['limits' => ['storage_mb' => 0]]);
        $this->tenant->forceFill(['plan_id' => $plan->id])->save();
        $this->tenant->unsetRelation('plan');

        $this->inShop(function () {
            $product = $this->product();

            $this->expectException(StorageLimitExceeded::class);

            $this->images->add($product, UploadedFile::fake()->image('foto.jpg')->size(2048));
        });
    }

    public function test_the_first_image_becomes_the_main_one(): void
    {
        $this->inShop(function () {
            $product = $this->product();

            $first = $this->images->add($product, UploadedFile::fake()->image('a.jpg'));
            $second = $this->images->add($product, UploadedFile::fake()->image('b.jpg'));

            $this->assertTrue($first->is_main);
            $this->assertFalse($second->is_main);
        });
    }

    public function test_promoting_an_image_demotes_the_previous_main(): void
    {
        $this->inShop(function () {
            $product = $this->product();
            $first = $this->images->add($product, UploadedFile::fake()->image('a.jpg'));
            $second = $this->images->add($product, UploadedFile::fake()->image('b.jpg'));

            $this->images->makeMain($second);

            $this->assertFalse($first->fresh()->is_main);
            $this->assertTrue($second->fresh()->is_main);
        });
    }

    public function test_deleting_the_main_image_promotes_the_next_one(): void
    {
        // A product with images but no main image renders an empty tile in
        // every listing.
        $this->inShop(function () {
            $product = $this->product();
            $first = $this->images->add($product, UploadedFile::fake()->image('a.jpg'));
            $second = $this->images->add($product, UploadedFile::fake()->image('b.jpg'));

            $this->images->remove($first);

            $this->assertTrue($second->fresh()->is_main);
        });
    }

    public function test_removing_an_image_deletes_the_file(): void
    {
        $this->inShop(function () {
            $product = $this->product();
            $image = $this->images->add($product, UploadedFile::fake()->image('a.jpg'));
            $key = "tenants/{$this->tenant->id}/".$image->path;

            $this->images->remove($image);

            Storage::disk(FileStorage::PUBLIC_DISK)->assertMissing($key);
            $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
        });
    }

    public function test_alt_text_and_order_can_be_set(): void
    {
        $this->inShop(function () {
            $product = $this->product();
            $a = $this->images->add($product, UploadedFile::fake()->image('a.jpg'));
            $b = $this->images->add($product, UploadedFile::fake()->image('b.jpg'));

            $this->images->update($a, ['alt' => 'Notebook zepředu']);
            $this->images->reorder($product, [$b->id, $a->id]);

            $this->assertSame('Notebook zepředu', $a->fresh()->alt);
            $this->assertGreaterThan($b->fresh()->position, $a->fresh()->position);
        });
    }

    public function test_images_of_another_shop_are_invisible(): void
    {
        $other = Tenant::factory()->create();
        $this->activateModule($other, 'products');

        $this->inShop(function () {
            $product = $this->product();
            $this->images->add($product, UploadedFile::fake()->image('a.jpg'));
        });

        $this->assertSame(
            0,
            $this->context->runAs($other, fn () => ProductImage::query()->count())
        );
    }
}
