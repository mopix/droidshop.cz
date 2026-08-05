<?php

namespace Tests\Feature\Legal;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pages\Models\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * The platform's own legal documents, served under /pravni/.
 *
 * The prefix is load-bearing, not cosmetic. Since wave 3.1 a tenant's static
 * pages answer at /{slug} through Route::fallback(); a single-segment
 * platform route such as /cookies would match on a tenant host too, and
 * RequirePlatformHost's 404 comes AFTER the match, so the fallback would
 * never run and the tenant's page would disappear. Because
 * Modules\Pages\Lifecycle seeds `ochrana-osobnich-udaju` itself, that would
 * have been a certainty rather than a risk. A two-segment platform path
 * cannot collide with a one-segment tenant slug by construction.
 */
class LegalPagesTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('billing.company', [
            'name' => 'Miroslav Opletal',
            'ico' => '12345678',
            'dic' => 'CZ12345678',
            'address' => 'Testovací 1, 700 30 Ostrava',
            'email' => 'podpora@droidshop.cz',
            'vat_payer' => false,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function legalPaths(): array
    {
        return [
            'terms' => ['/pravni/obchodni-podminky'],
            'privacy' => ['/pravni/ochrana-osobnich-udaju'],
            'dpa' => ['/pravni/zpracovani-udaju'],
            'cookies' => ['/pravni/cookies'],
        ];
    }

    #[DataProvider('legalPaths')]
    public function test_the_document_answers_on_the_platform_host(string $path): void
    {
        $this->get('http://droidshop'.$path)
            ->assertOk()
            ->assertSee('Miroslav Opletal');
    }

    #[DataProvider('legalPaths')]
    public function test_the_document_is_not_reachable_on_a_tenant_host(string $path): void
    {
        Tenant::factory()->withDomain('obchod.droidshop')->create();

        $this->get('http://obchod.droidshop'.$path)->assertNotFound();
    }

    #[DataProvider('legalPaths')]
    public function test_the_document_carries_a_canonical_and_is_indexable(string $path): void
    {
        $response = $this->get('http://droidshop'.$path)->assertOk();

        $response->assertSee('rel="canonical"', escape: false);
        $response->assertDontSee('noindex', escape: false);
    }

    /**
     * Identification is read from config, never written into the text: a
     * change of address or company number would otherwise mean editing four
     * documents and hoping none was missed.
     */
    public function test_identification_comes_from_config_not_from_the_text(): void
    {
        config()->set('billing.company.ico', '87654321');

        $this->get('http://droidshop/pravni/obchodni-podminky')
            ->assertOk()
            ->assertSee('87654321');
    }

    /**
     * The collision this whole prefix exists to prevent. A tenant page whose
     * slug happens to match a platform document name must keep working.
     */
    public function test_a_tenant_page_named_like_a_platform_document_still_works(): void
    {
        $tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->artisan('modules:sync')->assertSuccessful();
        $this->activateModule($tenant, 'storefront');
        $this->activateModule($tenant, 'pages');

        $context = app(TenantContext::class);

        foreach (['cookies', 'obchodni-podminky', 'ochrana-osobnich-udaju'] as $slug) {
            $context->runAs($tenant, fn () => Page::query()->updateOrCreate(
                ['slug' => $slug],
                ['title' => 'Stránka '.$slug, 'body' => 'Obsah nájemce.', 'is_published' => true],
            ));

            $this->get('http://obchod.droidshop/'.$slug)
                ->assertOk()
                ->assertSee('Obsah nájemce.');
        }
    }

    public function test_an_unknown_legal_slug_is_404_not_a_file_read(): void
    {
        $this->get('http://droidshop/pravni/../../etc/passwd')->assertNotFound();
        $this->get('http://droidshop/pravni/neexistujici')->assertNotFound();
    }

    public function test_the_terms_state_that_the_tenant_is_the_seller(): void
    {
        $this->get('http://droidshop/pravni/obchodni-podminky')
            ->assertOk()
            ->assertSee('Prodávajícím vůči koncovým zákazníkům je vždy nájemce', escape: false);
    }

    /**
     * The processor/controller split is the substance of the whole set; a
     * copy-paste template that gets it backwards is the classic failure here.
     */
    public function test_the_dpa_names_the_tenant_as_controller(): void
    {
        $this->get('http://droidshop/pravni/zpracovani-udaju')
            ->assertOk()
            ->assertSee('Správcem je nájemce', escape: false)
            ->assertSee('čl. 28', escape: false);
    }
}
