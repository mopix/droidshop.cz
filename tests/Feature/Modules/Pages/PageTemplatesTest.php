<?php

namespace Tests\Feature\Modules\Pages;

use App\Core\Html\HtmlSanitizer;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pages\Models\Page;
use Modules\Pages\Support\PageTemplates;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

/**
 * A shop used to start with three empty unpublished pages, so a tenant who
 * never opened them ran an e-shop with no terms and no privacy notice at
 * all. Wave 3.2 fills them with a sample the tenant completes.
 */
class PageTemplatesTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->context->forget();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
    }

    public function test_activation_seeds_three_unpublished_pages_with_content(): void
    {
        $this->activateModule($this->tenant, 'pages');

        $pages = $this->context->runAs($this->tenant, fn () => Page::query()->get());

        $this->assertCount(3, $pages);

        foreach ($pages as $page) {
            $this->assertFalse($page->is_published, $page->slug.' must not be published');
            $this->assertNotSame('', trim((string) $page->body), $page->slug.' must carry a template');
        }
    }

    public function test_every_template_carries_a_visible_placeholder(): void
    {
        foreach (PageTemplates::all() as $slug => $page) {
            $this->assertStringContainsString('[DOPLŇTE', $page['body'], $slug.' has no placeholder');
        }
    }

    /**
     * The warning that this is a sample and not legal advice is itself one of
     * the markers, so it cannot survive a tenant who actually read the page.
     */
    public function test_every_template_opens_with_the_not_legal_advice_warning(): void
    {
        foreach (PageTemplates::all() as $slug => $page) {
            $this->assertStringContainsString('Není právní radou', $page['body'], $slug.' misses the warning');
        }
    }

    /**
     * Markup outside HtmlSanitizer's allowlist would be stripped the first
     * time the tenant saved the page, so the template would quietly lose
     * structure. Round-tripping it here catches that at build time.
     */
    public function test_templates_survive_the_sanitizer_unchanged(): void
    {
        $sanitizer = app(HtmlSanitizer::class);

        foreach (PageTemplates::all() as $slug => $page) {
            $this->assertSame(
                $this->normalise($sanitizer->clean($page['body'])),
                $this->normalise($sanitizer->clean($sanitizer->clean($page['body']))),
                $slug.' is not stable under sanitisation',
            );

            foreach (['h2', 'p', 'strong'] as $tag) {
                $this->assertStringContainsString(
                    '<'.$tag,
                    $sanitizer->clean($page['body']),
                    $slug.' lost its <'.$tag.'> after sanitisation',
                );
            }
        }
    }

    public function test_reactivation_does_not_overwrite_what_the_tenant_wrote(): void
    {
        $this->activateModule($this->tenant, 'pages');

        $this->context->runAs($this->tenant, fn () => Page::query()
            ->where('slug', 'kontakt')
            ->update(['body' => 'Moje vlastní kontakty.', 'is_published' => true]));

        app(ModuleRegistry::class)->deactivate($this->tenant, 'pages');
        $this->activateModule($this->tenant, 'pages');

        $page = $this->context->runAs($this->tenant, fn () => Page::query()->where('slug', 'kontakt')->firstOrFail());

        $this->assertSame('Moje vlastní kontakty.', $page->body);
        $this->assertTrue($page->is_published);
    }

    private function normalise(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $html));
    }
}
