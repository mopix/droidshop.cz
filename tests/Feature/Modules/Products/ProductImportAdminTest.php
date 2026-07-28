<?php

namespace Tests\Feature\Modules\Products;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImport;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class ProductImportAdminTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('queue.default', 'sync');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('shop1.droidshop')->create();
        $this->activateModule($this->tenant, 'products');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    private function url(string $path = ''): string
    {
        return 'http://shop1.droidshop/admin/m/products/import'.$path;
    }

    private function file(string $contents = "typ;sku;nazev;cena;dph;stav\nprodukt;A-1;První;100,00;21;aktivni\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('katalog.csv', $contents);
    }

    public function test_the_screen_renders_with_the_run_history(): void
    {
        $this->actingAs($this->owner)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Products/Import')
                ->has('imports')
                ->has('columns')
            );
    }

    public function test_an_upload_runs_the_import(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), ['file' => $this->file()])
            ->assertRedirect();

        $this->context->runAs($this->tenant, function () {
            $this->assertSame(1, Product::query()->count());
            $this->assertSame(ProductImport::STATUS_DONE, ProductImport::query()->firstOrFail()->status);
        });
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), ['file' => $this->file(), 'dry_run' => '1'])
            ->assertRedirect();

        $this->assertSame(0, $this->context->runAs($this->tenant, fn () => Product::query()->count()));
    }

    public function test_a_non_csv_upload_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url(), ['file' => UploadedFile::fake()->create('katalog.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');
    }

    public function test_the_report_of_another_tenant_is_not_reachable(): void
    {
        $other = Tenant::factory()->withDomain('shop2.droidshop')->create();

        $foreign = $this->context->runAs($other, fn () => ProductImport::query()->create([
            'original_name' => 'cizi.csv',
            'path' => 'imports/cizi.csv',
            'status' => ProductImport::STATUS_DONE,
            'dry_run' => false,
            'report_path' => 'imports/protokol-cizi.csv',
        ]));

        $this->actingAs($this->owner)
            ->get($this->url('/'.$foreign->id.'/protokol'))
            ->assertNotFound();
    }

    public function test_a_signed_out_visitor_gets_nothing(): void
    {
        $this->get($this->url())->assertRedirect();
    }
}
