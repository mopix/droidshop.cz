<?php

namespace Tests\Feature\Core;

use App\Core\Routing\RedirectRegistry;
use App\Core\Tenancy\TenantContext;
use App\Models\Redirect;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slug history is SEO capital (spec §15.3). A renamed category or product
 * whose old URL 404s throws away every link and every ranking that pointed
 * at it, so the old path has to keep answering.
 */
class RedirectRegistryTest extends TestCase
{
    use RefreshDatabase;

    private RedirectRegistry $redirects;

    private TenantContext $context;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');

        $this->redirects = app(RedirectRegistry::class);
        $this->context = app(TenantContext::class);
        $this->tenant = Tenant::factory()->create();
    }

    private function inShop(callable $callback): mixed
    {
        return $this->context->runAs($this->tenant, $callback);
    }

    public function test_a_recorded_move_resolves(): void
    {
        $this->inShop(function () {
            $this->redirects->record('/kategorie/stare', '/kategorie/nove');

            $this->assertSame('/kategorie/nove', $this->redirects->resolve('/kategorie/stare'));
        });
    }

    public function test_an_unknown_path_resolves_to_nothing(): void
    {
        $this->inShop(function () {
            $this->assertNull($this->redirects->resolve('/kategorie/nikdy-neexistovala'));
        });
    }

    public function test_recording_the_same_move_twice_does_not_duplicate(): void
    {
        $this->inShop(function () {
            $this->redirects->record('/a', '/b');
            $this->redirects->record('/a', '/b');

            $this->assertSame(1, Redirect::query()->count());
        });
    }

    public function test_a_second_rename_collapses_the_chain(): void
    {
        // A renamed twice: /a -> /b -> /c. Without collapsing, the first
        // visitor pays two round trips and search engines discount the chain.
        $this->inShop(function () {
            $this->redirects->record('/a', '/b');
            $this->redirects->record('/b', '/c');

            $this->assertSame('/c', $this->redirects->resolve('/a'));
            $this->assertSame('/c', $this->redirects->resolve('/b'));
        });
    }

    public function test_a_move_back_to_the_original_path_removes_the_redirect(): void
    {
        // /a -> /b -> /a. Leaving the rows in place would be a loop.
        $this->inShop(function () {
            $this->redirects->record('/a', '/b');
            $this->redirects->record('/b', '/a');

            $this->assertNull($this->redirects->resolve('/a'));
            $this->assertSame('/a', $this->redirects->resolve('/b'));
        });
    }

    public function test_a_redirect_belongs_to_one_shop_only(): void
    {
        $other = Tenant::factory()->create();

        $this->inShop(fn () => $this->redirects->record('/a', '/b'));

        $this->assertNull(
            $this->context->runAs($other, fn () => $this->redirects->resolve('/a'))
        );
    }

    public function test_paths_are_normalised_so_a_trailing_slash_is_not_a_different_url(): void
    {
        $this->inShop(function () {
            $this->redirects->record('kategorie/stare/', '/kategorie/nove');

            $this->assertSame('/kategorie/nove', $this->redirects->resolve('/kategorie/stare'));
        });
    }

    public function test_the_default_status_is_a_permanent_redirect(): void
    {
        $this->inShop(function () {
            $this->redirects->record('/a', '/b');

            $this->assertSame(301, Redirect::query()->first()->status);
        });
    }
}
