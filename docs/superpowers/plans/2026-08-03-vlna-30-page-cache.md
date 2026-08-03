# Vlna 3.0 — Page cache storefrontu — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Anonymní GET na storefrontu obslouží uložené HTML místo renderu, s invalidací, která nezávisí na Redisu a nikdy neservíruje osobní obsah cizímu návštěvníkovi.

**Architecture:** Middleware `page-cache` na vybraných storefront routách, běžící za `StartSession`. Klíč nese generační razítko složené ze sloupců `tenants`; zápis do modelů katalogu, obsahu a vzhledu razítko zvedne přes jeden observer. CSRF token se před uložením nahradí značkou a při odeslání vrátí čerstvý.

**Tech Stack:** Laravel 13, PHPUnit, `Illuminate\Support\Facades\Cache` (libovolný driver — žádné tagy), Blade SSR.

Spec: [`docs/superpowers/specs/2026-08-03-vlna-30-page-cache-design.md`](../specs/2026-08-03-vlna-30-page-cache-design.md)

## Global Constraints

- **Žádné tagy cache.** `Cache::tags()` se v celé vlně nesmí objevit — funguje jen na Redisu a jeho absence by se projevila tiše.
- **Žádná nová závislost.** `composer.json` ani `package.json` se nemění.
- **Kód anglicky** (názvy, komentáře, commit zprávy), uživatelské texty česky.
- **PHP 8.3**, žádné 8.4 konstrukce (property hooks, `array_find`).
- **`./vendor/bin/pint`** na dotčené soubory před každým commitem.
- **Testy dědí `Tests\TestCase`**, používají `RefreshDatabase` a v `setUp` nastavují `config()->set('cache.default', 'array')` a `config()->set('tenancy.platform_domain', 'droidshop')` — stejně jako `tests/Feature/Storefront/StorefrontCatalogTest.php`.
- **Cache se v testech nezapíná sama.** `config/pagecache.php` má `enabled => env('PAGE_CACHE_ENABLED', true)`; testy, které cache nechtějí, ji vypnou explicitně. Existující testovací sada nesmí po této vlně padat.
- **Nikdy neukládat `Set-Cookie`.** Do uloženého záznamu jde jen tělo, stavový kód a `Content-Type`.

---

### Task 1: Generační čítače ve sloupcích `tenants`

**Files:**
- Create: `database/migrations/2026_08_03_100000_add_page_generation_counters_to_tenants_table.php`
- Create: `app/Core/PageCache/Dimension.php`
- Create: `app/Core/PageCache/Generations.php`
- Test: `tests/Feature/PageCache/GenerationsTest.php`

**Interfaces:**
- Consumes: `App\Models\Tenant`
- Produces:
  - `App\Core\PageCache\Dimension` — enum `Catalog|Content|Theme`, `string $value` = `catalog|content|theme`, metoda `column(): string`
  - `App\Core\PageCache\Generations::stamp(Tenant $tenant, array $dimensions): string` — `$dimensions` je `list<Dimension>`; vrací tečkou spojená čísla v pořadí, v jakém přišla
  - `App\Core\PageCache\Generations::bump(Tenant $tenant, Dimension $dimension): void`
  - `App\Core\PageCache\Generations::bumpAll(Tenant $tenant): void`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\PageCache;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationsTest extends TestCase
{
    use RefreshDatabase;

    private Generations $generations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->generations = app(Generations::class);
    }

    public function test_a_fresh_tenant_starts_at_generation_one(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame('1.1', $this->generations->stamp($tenant, [Dimension::Catalog, Dimension::Theme]));
    }

    public function test_bumping_one_dimension_leaves_the_others_alone(): void
    {
        $tenant = Tenant::factory()->create();

        $this->generations->bump($tenant, Dimension::Catalog);

        $this->assertSame('2', $this->generations->stamp($tenant, [Dimension::Catalog]));
        $this->assertSame('1', $this->generations->stamp($tenant, [Dimension::Theme]));
        $this->assertSame('1', $this->generations->stamp($tenant, [Dimension::Content]));
    }

    public function test_the_stamp_follows_the_order_the_dimensions_were_asked_for(): void
    {
        $tenant = Tenant::factory()->create();

        $this->generations->bump($tenant, Dimension::Theme);

        $this->assertSame('1.2', $this->generations->stamp($tenant, [Dimension::Catalog, Dimension::Theme]));
        $this->assertSame('2.1', $this->generations->stamp($tenant, [Dimension::Theme, Dimension::Catalog]));
    }

    public function test_bump_all_moves_every_dimension(): void
    {
        $tenant = Tenant::factory()->create();

        $this->generations->bumpAll($tenant);

        $this->assertSame('2.2.2', $this->generations->stamp(
            $tenant,
            [Dimension::Catalog, Dimension::Content, Dimension::Theme],
        ));
    }

    public function test_a_bump_is_visible_on_the_instance_that_triggered_it(): void
    {
        $tenant = Tenant::factory()->create();

        $this->generations->bump($tenant, Dimension::Catalog);
        $this->generations->bump($tenant, Dimension::Catalog);

        // Without refreshing the in-memory attribute the second bump would
        // read a stale 1 and the stamp would lag a request behind the data.
        $this->assertSame('3', $this->generations->stamp($tenant, [Dimension::Catalog]));
    }

    public function test_one_tenants_bump_does_not_move_another(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->generations->bump($a, Dimension::Catalog);

        $this->assertSame('1', $this->generations->stamp($b, [Dimension::Catalog]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GenerationsTest`
Expected: FAIL — `Class "App\Core\PageCache\Dimension" not found`

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Page-cache generation counters (wave 3.0).
     *
     * These live on the tenant row rather than in the cache store on purpose.
     * A counter kept in cache can be evicted; it would come back as 1 and any
     * page still stored under the original generation 1 would be served again
     * — content the tenant changed long ago, resurrected. The tenant row is
     * loaded on every request anyway (DomainTenantFinder), so three integer
     * columns cost no extra query.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('page_gen_catalog')->default(1);
            $table->unsignedBigInteger('page_gen_content')->default(1);
            $table->unsignedBigInteger('page_gen_theme')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['page_gen_catalog', 'page_gen_content', 'page_gen_theme']);
        });
    }
};
```

- [ ] **Step 4: Write `Dimension`**

```php
<?php

namespace App\Core\PageCache;

/**
 * What a cached page depends on. A page's key carries only the dimensions it
 * actually reads, so recolouring the shop does not drop the catalogue and
 * editing a product does not drop the static pages.
 */
enum Dimension: string
{
    case Catalog = 'catalog';
    case Content = 'content';
    case Theme = 'theme';

    public function column(): string
    {
        return 'page_gen_'.$this->value;
    }

    /**
     * Parses the middleware parameters (`page-cache:catalog,theme`).
     *
     * @param  list<string>  $values
     * @return list<self>
     */
    public static function list(array $values): array
    {
        return array_map(static fn (string $value): self => self::from($value), $values);
    }
}
```

- [ ] **Step 5: Write `Generations`**

```php
<?php

namespace App\Core\PageCache;

use App\Models\Tenant;

/**
 * Generation counters, the whole invalidation mechanism (spec §15.6 rewritten
 * for wave 3.0). Bumping a counter orphans every key stamped with the old
 * value; the orphans expire on their own TTL. Nothing is enumerated and
 * nothing is deleted, so this works on any cache driver — unlike tags, which
 * only Redis implements and whose absence fails silently.
 */
class Generations
{
    /**
     * @param  list<Dimension>  $dimensions
     */
    public function stamp(Tenant $tenant, array $dimensions): string
    {
        $parts = array_map(
            static fn (Dimension $dimension): string => (string) ($tenant->{$dimension->column()} ?? 1),
            $dimensions,
        );

        return implode('.', $parts);
    }

    public function bump(Tenant $tenant, Dimension $dimension): void
    {
        Tenant::query()->whereKey($tenant->getKey())->increment($dimension->column());

        // The caller usually holds this instance for the rest of the request.
        // Leaving the attribute stale would stamp the next key with the value
        // the data had before the write.
        $tenant->setAttribute(
            $dimension->column(),
            (int) ($tenant->{$dimension->column()} ?? 1) + 1,
        );
        $tenant->syncOriginalAttribute($dimension->column());
    }

    public function bumpAll(Tenant $tenant): void
    {
        foreach (Dimension::cases() as $dimension) {
            $this->bump($tenant, $dimension);
        }
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=GenerationsTest`
Expected: PASS, 6 tests

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app/Core/PageCache database/migrations tests/Feature/PageCache
git add app/Core/PageCache database/migrations tests/Feature/PageCache
git commit -m "feat(pagecache): add generation counters on the tenant row"
```

---

### Task 2: Klíč a normalizace query stringu

**Files:**
- Create: `config/pagecache.php`
- Create: `app/Core/PageCache/PageCacheKey.php`
- Test: `tests/Feature/PageCache/PageCacheKeyTest.php`

**Interfaces:**
- Consumes: `Generations::stamp()`, `Dimension`
- Produces: `App\Core\PageCache\PageCacheKey::for(Request $request, Tenant $tenant, array $dimensions): string`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\PageCache;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Core\PageCache\PageCacheKey;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PageCacheKeyTest extends TestCase
{
    use RefreshDatabase;

    private PageCacheKey $keys;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->keys = app(PageCacheKey::class);
    }

    private function key(Tenant $tenant, string $uri): string
    {
        return $this->keys->for(Request::create($uri), $tenant, [Dimension::Catalog]);
    }

    public function test_the_key_carries_tenant_generation_and_path(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(
            'page:'.$tenant->id.':1:/kategorie/boty',
            $this->key($tenant, '/kategorie/boty'),
        );
    }

    public function test_two_tenants_never_share_a_key(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->assertNotSame($this->key($a, '/kategorie/boty'), $this->key($b, '/kategorie/boty'));
    }

    public function test_bumping_the_generation_changes_the_key(): void
    {
        $tenant = Tenant::factory()->create();
        $before = $this->key($tenant, '/kategorie/boty');

        app(Generations::class)->bump($tenant, Dimension::Catalog);

        $this->assertNotSame($before, $this->key($tenant, '/kategorie/boty'));
    }

    public function test_unknown_query_parameters_are_dropped(): void
    {
        $tenant = Tenant::factory()->create();

        // Marketing parameters must not fragment the cache: the application
        // ignores them (ProductQuery::fromInput drops what it does not know),
        // so the key may ignore them too.
        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?utm_source=fb&fbclid=xyz'),
        );
    }

    public function test_whitelisted_parameters_do_change_the_key(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNotSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?razeni=cena-vzestupne'),
        );

        $this->assertNotSame(
            $this->key($tenant, '/kategorie/boty?strana=2'),
            $this->key($tenant, '/kategorie/boty?strana=3'),
        );
    }

    public function test_parameter_order_does_not_matter(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(
            $this->key($tenant, '/kategorie/boty?razeni=cena-vzestupne&skladem=1'),
            $this->key($tenant, '/kategorie/boty?skladem=1&razeni=cena-vzestupne'),
        );
    }

    public function test_array_shaped_parameters_are_ignored(): void
    {
        $tenant = Tenant::factory()->create();

        // ?razeni[]=a&razeni[]=b must not blow up key building or let an
        // attacker mint unbounded distinct keys from one whitelisted name.
        $this->assertSame(
            $this->key($tenant, '/kategorie/boty'),
            $this->key($tenant, '/kategorie/boty?razeni[]=a&razeni[]=b'),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PageCacheKeyTest`
Expected: FAIL — `Class "App\Core\PageCache\PageCacheKey" not found`

- [ ] **Step 3: Write the config**

```php
<?php

return [

    /*
     * Global off switch (spec: superadmin emergency brake). Turning this off
     * returns the storefront to its pre-wave-3.0 behaviour without touching
     * a line of code.
     */
    'enabled' => env('PAGE_CACHE_ENABLED', true),

    /*
     * Cache store to use. Null means the application default. No tags are
     * used anywhere, so file, database and Redis all work.
     */
    'store' => env('PAGE_CACHE_STORE'),

    'ttl' => [
        'default' => 600,       // 10 min — spec §15.6
        'not_found' => 3600,    // in-route 404s (unknown slug)
        'search' => 300,
    ],

    /*
     * Query parameters allowed to fragment the cache. Everything else is
     * dropped from the key, so marketing parameters land on the same entry
     * as the bare URL and nobody can mint unbounded keys.
     */
    'query_whitelist' => ['razeni', 'skladem', 'strana', 'page', 'q'],

    /*
     * Search terms longer than this are never cached — `?q=` has unbounded
     * cardinality and is the obvious way to fill the store on purpose.
     */
    'search_term_max' => 60,

];
```

- [ ] **Step 4: Write `PageCacheKey`**

```php
<?php

namespace App\Core\PageCache;

use App\Models\Tenant;
use Illuminate\Http\Request;

class PageCacheKey
{
    public function __construct(private readonly Generations $generations) {}

    /**
     * @param  list<Dimension>  $dimensions
     */
    public function for(Request $request, Tenant $tenant, array $dimensions): string
    {
        $key = 'page:'.$tenant->getKey()
            .':'.$this->generations->stamp($tenant, $dimensions)
            .':/'.trim($request->path(), '/');

        $query = $this->normaliseQuery($request);

        return $query === '' ? $key : $key.':'.substr(hash('sha256', $query), 0, 16);
    }

    /**
     * Keeps only whitelisted scalar parameters, in a fixed order. Anything
     * else is noise the application itself ignores.
     */
    private function normaliseQuery(Request $request): string
    {
        /** @var list<string> $allowed */
        $allowed = config('pagecache.query_whitelist', []);

        $params = [];

        foreach ($allowed as $name) {
            $value = $request->query($name);

            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $params[$name] = (string) $value;
        }

        ksort($params);

        return http_build_query($params);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=PageCacheKeyTest`
Expected: PASS, 7 tests

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint app/Core/PageCache config/pagecache.php tests/Feature/PageCache
git add app/Core/PageCache config/pagecache.php tests/Feature/PageCache
git commit -m "feat(pagecache): build keys from the generation stamp and a whitelisted query"
```

---

### Task 3: Politika — kdo se cachuje a kdo ne

**Files:**
- Create: `app/Core/PageCache/PageCachePolicy.php`
- Test: `tests/Feature/PageCache/PageCachePolicyTest.php`

**Interfaces:**
- Consumes: `App\Core\Tenancy\TenantContext` (`current(): ?Tenant`), `App\Models\Tenant::allowsStorefront()`
- Produces:
  - `App\Core\PageCache\PageCachePolicy::tenantFor(Request $request): ?Tenant` — nájemce, pro kterého se smí číst i psát; `null` = cache se vůbec nepoužije
  - `App\Core\PageCache\PageCachePolicy::mayStore(Response $response): bool`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\PageCache;

use App\Core\Enums\TenantStatus;
use App\Core\PageCache\PageCachePolicy;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class PageCachePolicyTest extends TestCase
{
    use RefreshDatabase;

    private PageCachePolicy $policy;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('pagecache.enabled', true);

        $this->policy = app(PageCachePolicy::class);
        $this->context = app(TenantContext::class);
        $this->context->forget();
    }

    private function get(string $uri = '/kategorie/boty'): Request
    {
        return Request::create($uri, 'GET');
    }

    public function test_an_anonymous_get_on_a_running_shop_is_cacheable(): void
    {
        $tenant = Tenant::factory()->create();
        $this->context->set($tenant);

        $this->assertTrue($this->policy->tenantFor($this->get())->is($tenant));
    }

    public function test_a_request_without_a_tenant_is_not_cacheable(): void
    {
        $this->assertNull($this->policy->tenantFor($this->get()));
    }

    public function test_a_post_is_not_cacheable(): void
    {
        $this->context->set(Tenant::factory()->create());

        $this->assertNull($this->policy->tenantFor(Request::create('/kosik', 'POST')));
    }

    public function test_a_signed_in_customer_bypasses_the_cache(): void
    {
        $this->context->set(Tenant::factory()->create());

        // The header renders "Můj účet" instead of "Přihlásit se" for them
        // (shop.blade.php). Storing that would hand one visitor's state to
        // the next anonymous one.
        $this->app['auth']->shouldUse('customer');
        $this->assertNull($this->policy->tenantFor($this->get()));
    }

    public function test_a_suspended_shop_is_not_cacheable(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
        $this->context->set($tenant);

        $this->assertNull($this->policy->tenantFor($this->get()));
    }

    public function test_the_global_switch_turns_everything_off(): void
    {
        config()->set('pagecache.enabled', false);
        $this->context->set(Tenant::factory()->create());

        $this->assertNull($this->policy->tenantFor($this->get()));
    }

    public function test_only_ok_and_gone_responses_may_be_stored(): void
    {
        $this->assertTrue($this->policy->mayStore(new Response('ok', 200)));
        $this->assertTrue($this->policy->mayStore(new Response('gone', 404)));
        $this->assertTrue($this->policy->mayStore(new Response('gone', 410)));
        $this->assertFalse($this->policy->mayStore(new Response('boom', 500)));
        $this->assertFalse($this->policy->mayStore(new Response('go', 302)));
    }

    public function test_a_private_response_is_never_stored(): void
    {
        $private = new Response('cart', 200, ['Cache-Control' => 'private, no-store']);

        $this->assertFalse($this->policy->mayStore($private));
    }

    public function test_a_response_that_sets_a_cookie_is_never_stored(): void
    {
        $response = new Response('ok', 200);
        $response->headers->setCookie(cookie('flash', 'x'));

        $this->assertFalse($this->policy->mayStore($response));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PageCachePolicyTest`
Expected: FAIL — `Class "App\Core\PageCache\PageCachePolicy" not found`

- [ ] **Step 3: Write `PageCachePolicy`**

```php
<?php

namespace App\Core\PageCache;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one place that decides whether a request may touch the shared page
 * cache. Everything here guards the iron rule of spec §15.6: cached HTML must
 * carry nothing that belongs to a single visitor. A mistake in this class is
 * a leak between customers — the same class of bug as a leak between tenants.
 */
class PageCachePolicy
{
    private const STORABLE_STATUSES = [200, 404, 410];

    public function __construct(private readonly TenantContext $context) {}

    public function tenantFor(Request $request): ?Tenant
    {
        if (! config('pagecache.enabled', true)) {
            return null;
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return null;
        }

        $tenant = $this->context->current();

        if ($tenant === null || ! $tenant->allowsStorefront()) {
            return null;
        }

        if ($this->hasVisitorState($request)) {
            return null;
        }

        return $tenant;
    }

    public function mayStore(Response $response): bool
    {
        if (! in_array($response->getStatusCode(), self::STORABLE_STATUSES, true)) {
            return false;
        }

        // A response that sets a cookie is answering this visitor personally.
        if ($response->headers->getCookies() !== []) {
            return false;
        }

        $control = (string) $response->headers->get('Cache-Control', '');

        return ! str_contains($control, 'private') && ! str_contains($control, 'no-store');
    }

    /**
     * Anything that makes this visitor's HTML differ from the next one's.
     */
    private function hasVisitorState(Request $request): bool
    {
        if (auth()->guard('customer')->check()) {
            return true;
        }

        // Staff browsing their own shop, and impersonation, both render extra
        // affordances; neither may be handed to a shopper.
        if (auth()->guard('web')->check()) {
            return true;
        }

        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();

        return $session->has('errors')
            || $session->has('status')
            || $session->has('success')
            || $session->has('error');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PageCachePolicyTest`
Expected: PASS, 9 tests

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Core/PageCache tests/Feature/PageCache
git add app/Core/PageCache tests/Feature/PageCache
git commit -m "feat(pagecache): gate the shared cache on visitor state and shop status"
```

---

### Task 4: Záměna CSRF tokenu

**Files:**
- Create: `app/Core/PageCache/DynamicTokens.php`
- Test: `tests/Unit/PageCache/DynamicTokensTest.php`

**Interfaces:**
- Produces:
  - `App\Core\PageCache\DynamicTokens::MARKER` — konstanta `'@@PAGECACHE_CSRF@@'`
  - `App\Core\PageCache\DynamicTokens::mask(string $html, string $token): string`
  - `App\Core\PageCache\DynamicTokens::unmask(string $html, string $token): string`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\PageCache;

use App\Core\PageCache\DynamicTokens;
use PHPUnit\Framework\TestCase;

class DynamicTokensTest extends TestCase
{
    private DynamicTokens $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = new DynamicTokens;
    }

    public function test_masking_replaces_every_occurrence_of_the_token(): void
    {
        $html = '<input value="abc123"><input value="abc123">';

        $this->assertSame(
            '<input value="'.DynamicTokens::MARKER.'"><input value="'.DynamicTokens::MARKER.'">',
            $this->tokens->mask($html, 'abc123'),
        );
    }

    public function test_unmasking_puts_the_asking_visitors_token_in(): void
    {
        $stored = '<input value="'.DynamicTokens::MARKER.'">';

        $this->assertSame('<input value="zzz999">', $this->tokens->unmask($stored, 'zzz999'));
    }

    public function test_a_round_trip_hands_a_different_visitor_a_different_token(): void
    {
        $rendered = '<form><input name="_token" value="first-session-token"></form>';

        $stored = $this->tokens->mask($rendered, 'first-session-token');
        $served = $this->tokens->unmask($stored, 'second-session-token');

        $this->assertStringContainsString('second-session-token', $served);
        $this->assertStringNotContainsString('first-session-token', $served);
    }

    public function test_an_empty_token_leaves_the_html_untouched(): void
    {
        // str_replace with an empty needle would corrupt the document.
        $html = '<p>hello</p>';

        $this->assertSame($html, $this->tokens->mask($html, ''));
    }

    public function test_html_without_a_token_survives_masking(): void
    {
        $html = '<p>no form here</p>';

        $this->assertSame($html, $this->tokens->mask($html, 'abc123'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DynamicTokensTest`
Expected: FAIL — `Class "App\Core\PageCache\DynamicTokens" not found`

- [ ] **Step 3: Write `DynamicTokens`**

```php
<?php

namespace App\Core\PageCache;

/**
 * The CSRF token is per session, and the product detail page carries one in
 * the add-to-cart form. Storing the rendered token would hand visitor A's
 * token to visitor B and their add-to-cart would end in a 419.
 *
 * Substitution works on the rendered value rather than on a Blade directive,
 * so a form added to a cached page later is covered without anyone
 * remembering this class exists.
 */
class DynamicTokens
{
    public const MARKER = '@@PAGECACHE_CSRF@@';

    public function mask(string $html, string $token): string
    {
        if ($token === '') {
            return $html;
        }

        return str_replace($token, self::MARKER, $html);
    }

    public function unmask(string $html, string $token): string
    {
        return str_replace(self::MARKER, $token, $html);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=DynamicTokensTest`
Expected: PASS, 5 tests

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Core/PageCache tests/Unit/PageCache
git add app/Core/PageCache tests/Unit/PageCache
git commit -m "feat(pagecache): swap the CSRF token for a marker before storing"
```

---

### Task 5: Middleware a napojení na routy

**Files:**
- Create: `app/Http/Middleware/CacheStorefrontPage.php`
- Modify: `bootstrap/app.php` — alias `page-cache` v `$middleware->alias([...])` (kolem řádku 68)
- Modify: `routes/web.php:23` — homepage
- Modify: `Modules/Products/routes/storefront.php:8`
- Modify: `Modules/Categories/routes/storefront.php:6`
- Modify: `Modules/Pages/routes/storefront.php:12`
- Test: `tests/Feature/PageCache/PageCacheMiddlewareTest.php`

**Interfaces:**
- Consumes: `PageCachePolicy::tenantFor()`, `PageCachePolicy::mayStore()`, `PageCacheKey::for()`, `DynamicTokens::mask()`/`unmask()`, `Dimension::list()`
- Produces: alias middlewaru `page-cache`, parametrizovaný dimenzemi — `page-cache:catalog,theme`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\PageCache;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PageCacheMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('pagecache.enabled', true);

        // A route of our own keeps the test about the middleware rather than
        // about whatever the catalogue happens to render today.
        Route::middleware(['web', 'page-cache:catalog'])->get('/pc-probe', function () {
            return response('<p>rendered '.now()->format('u').'</p><input value="'.csrf_token().'">');
        });
    }

    private function shop(string $subdomain = 'obchod'): Tenant
    {
        return Tenant::factory()->withDomain($subdomain.'.droidshop')->create();
    }

    public function test_the_second_request_is_served_from_the_cache(): void
    {
        $tenant = $this->shop();

        $first = $this->get('http://obchod.droidshop/pc-probe')->assertOk()->getContent();
        $second = $this->get('http://obchod.droidshop/pc-probe')->assertOk()->getContent();

        $this->assertSame(
            strip_tags(explode('<input', $first)[0]),
            strip_tags(explode('<input', $second)[0]),
        );
    }

    public function test_a_cache_hit_runs_no_catalogue_queries(): void
    {
        $this->shop();
        $this->get('http://obchod.droidshop/pc-probe')->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get('http://obchod.droidshop/pc-probe')->assertOk();

        // Resolving the tenant still costs a query or two; rendering must not.
        $this->assertLessThanOrEqual(3, $queries);
    }

    public function test_each_visitor_gets_their_own_csrf_token(): void
    {
        $this->shop();

        $first = $this->get('http://obchod.droidshop/pc-probe')->getContent();
        $firstToken = $this->tokenIn($first);

        $this->flushSession();

        $second = $this->get('http://obchod.droidshop/pc-probe')->getContent();
        $secondToken = $this->tokenIn($second);

        $this->assertNotSame('', $firstToken);
        $this->assertNotSame('', $secondToken);
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertStringNotContainsString('@@PAGECACHE_CSRF@@', $second);
    }

    public function test_one_shop_never_receives_another_shops_page(): void
    {
        $this->shop('prvni');
        $this->shop('druhy');

        $first = $this->get('http://prvni.droidshop/pc-probe')->getContent();
        $second = $this->get('http://druhy.droidshop/pc-probe')->getContent();

        $this->assertNotSame(
            strip_tags(explode('<input', $first)[0]),
            strip_tags(explode('<input', $second)[0]),
        );
    }

    public function test_bumping_the_generation_re_renders(): void
    {
        $tenant = $this->shop();

        $before = $this->get('http://obchod.droidshop/pc-probe')->getContent();

        app(Generations::class)->bump($tenant->fresh(), Dimension::Catalog);

        $after = $this->get('http://obchod.droidshop/pc-probe')->getContent();

        $this->assertNotSame(
            strip_tags(explode('<input', $before)[0]),
            strip_tags(explode('<input', $after)[0]),
        );
    }

    public function test_bumping_an_unrelated_dimension_keeps_the_page(): void
    {
        $tenant = $this->shop();

        $before = $this->get('http://obchod.droidshop/pc-probe')->getContent();

        app(Generations::class)->bump($tenant->fresh(), Dimension::Theme);

        $after = $this->get('http://obchod.droidshop/pc-probe')->getContent();

        $this->assertSame(
            strip_tags(explode('<input', $before)[0]),
            strip_tags(explode('<input', $after)[0]),
        );
    }

    public function test_the_response_never_carries_a_stored_cookie(): void
    {
        $this->shop();

        $this->get('http://obchod.droidshop/pc-probe');
        $response = $this->get('http://obchod.droidshop/pc-probe');

        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertNotSame('flash', $cookie->getName());
        }

        $response->assertOk();
    }

    public function test_disabling_the_cache_renders_every_time(): void
    {
        config()->set('pagecache.enabled', false);
        $this->shop();

        $first = $this->get('http://obchod.droidshop/pc-probe')->getContent();
        $second = $this->get('http://obchod.droidshop/pc-probe')->getContent();

        $this->assertNotSame(
            strip_tags(explode('<input', $first)[0]),
            strip_tags(explode('<input', $second)[0]),
        );
    }

    private function tokenIn(string $html): string
    {
        preg_match('/<input value="([^"]*)"/', $html, $matches);

        return $matches[1] ?? '';
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PageCacheMiddlewareTest`
Expected: FAIL — `Target class [page-cache] does not exist`

- [ ] **Step 3: Write the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\DynamicTokens;
use App\Core\PageCache\PageCacheKey;
use App\Core\PageCache\PageCachePolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Whole-HTML cache for anonymous storefront GETs (spec §15.6).
 *
 * Opt-in per route, never global: a route added later must not start caching
 * because somebody forgot it inherits this. The dimensions the page depends
 * on are middleware parameters — `page-cache:catalog,theme`.
 *
 * Runs behind StartSession on purpose. Without a session there is no CSRF
 * token to substitute back in, and no way to tell a signed-in shopper from an
 * anonymous one.
 */
class CacheStorefrontPage
{
    public function __construct(
        private readonly PageCachePolicy $policy,
        private readonly PageCacheKey $keys,
        private readonly DynamicTokens $tokens,
    ) {}

    public function handle(Request $request, Closure $next, string ...$dimensions): Response
    {
        $tenant = $this->policy->tenantFor($request);

        if ($tenant === null) {
            return $next($request);
        }

        $key = $this->keys->for($request, $tenant, Dimension::list($dimensions));
        $store = Cache::store(config('pagecache.store'));

        /** @var array{body: string, status: int, type: string}|null $stored */
        $stored = $store->get($key);

        if ($stored !== null) {
            return $this->rebuild($stored, $request);
        }

        $response = $next($request);

        if ($this->policy->mayStore($response)) {
            $store->put($key, [
                'body' => $this->tokens->mask((string) $response->getContent(), (string) csrf_token()),
                'status' => $response->getStatusCode(),
                'type' => (string) $response->headers->get('Content-Type', 'text/html; charset=UTF-8'),
            ], $this->ttl($response));
        }

        return $response;
    }

    /**
     * @param  array{body: string, status: int, type: string}  $stored
     */
    private function rebuild(array $stored, Request $request): Response
    {
        // Only the body, the status and the content type come back. Set-Cookie
        // is never stored and never replayed — Laravel attaches this visitor's
        // own session cookie on the way out, because this middleware sits
        // behind StartSession.
        return response(
            $this->tokens->unmask($stored['body'], (string) csrf_token()),
            $stored['status'],
            ['Content-Type' => $stored['type']],
        );
    }

    private function ttl(Response $response): int
    {
        if (in_array($response->getStatusCode(), [404, 410], true)) {
            return (int) config('pagecache.ttl.not_found', 3600);
        }

        return (int) config('pagecache.ttl.default', 600);
    }
}
```

- [ ] **Step 4: Register the alias**

V `bootstrap/app.php`, do pole `$middleware->alias([...])` (kolem řádku 68) přidej řádek a nahoře `use App\Http\Middleware\CacheStorefrontPage;`:

```php
        $middleware->alias([
            'platform.host' => RequirePlatformHost::class,
            'platform.2fa' => EnsurePlatformTwoFactor::class,
            'tenant.member' => EnsureTenantMember::class,
            'internal.local' => AllowLocalOnly::class,
            // Opt-in per route (wave 3.0). Appended to the web group by the
            // routes that want it, so it lands behind StartSession.
            'page-cache' => CacheStorefrontPage::class,
        ]);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=PageCacheMiddlewareTest`
Expected: PASS, 8 tests

- [ ] **Step 6: Attach the middleware to the storefront routes**

`routes/web.php:23`:

```php
Route::get('/', StorefrontEntryController::class)
    ->middleware('page-cache:catalog,content,theme')
    ->name('home');
```

`Modules/Products/routes/storefront.php:8`:

```php
Route::get('/produkt/{slug}', ProductStorefrontController::class)
    ->middleware('page-cache:catalog,theme')
    ->name('show');
```

`Modules/Categories/routes/storefront.php:6`:

```php
Route::get('/kategorie/{slug}', CategoryStorefrontController::class)
    ->middleware('page-cache:catalog,theme')
    ->name('show');
```

`Modules/Pages/routes/storefront.php:12`:

```php
Route::get('/stranka/{slug}', [PageController::class, 'show'])
    ->middleware('page-cache:content,theme')
    ->name('show');
```

- [ ] **Step 7: Run the storefront suite to prove nothing regressed**

Run: `php artisan test --filter="Storefront|Checkout|Products|Categories|Pages"`
Expected: PASS — žádný existující test nesmí spadnout

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint app/Http/Middleware bootstrap/app.php routes Modules tests/Feature/PageCache
git add app/Http/Middleware bootstrap/app.php routes/web.php Modules/Products/routes Modules/Categories/routes Modules/Pages/routes tests/Feature/PageCache
git commit -m "feat(pagecache): serve anonymous storefront GETs from the cache"
```

---

### Task 6: Invalidace přes observer

**Files:**
- Create: `app/Core/PageCache/PageCacheObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` — registrace observerů v `boot()`
- Test: `tests/Feature/PageCache/PageCacheInvalidationTest.php`

**Interfaces:**
- Consumes: `Generations::bump()`, `Dimension`, `App\Core\Tenancy\TenantContext::current()`
- Produces: `App\Core\PageCache\PageCacheObserver` s `saved(Model $model)` a `deleted(Model $model)`; mapu model → dimenze drží statické pole `PageCacheObserver::DIMENSION_BY_MODEL`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\PageCache;

use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class PageCacheInvalidationTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private TenantContext $context;

    private Generations $generations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->context = app(TenantContext::class);
        $this->generations = app(Generations::class);
    }

    private function shop(): Tenant
    {
        $tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($tenant, 'products');
        $this->activateModule($tenant, 'storefront');
        $this->context->set($tenant);

        return $tenant;
    }

    public function test_saving_a_product_bumps_the_catalogue(): void
    {
        $tenant = $this->shop();

        Product::factory()->create();

        $this->assertSame('2', $this->generations->stamp($tenant->fresh(), [Dimension::Catalog]));
    }

    public function test_saving_a_product_leaves_content_and_theme_alone(): void
    {
        $tenant = $this->shop();

        Product::factory()->create();

        $fresh = $tenant->fresh();
        $this->assertSame('1', $this->generations->stamp($fresh, [Dimension::Content]));
        $this->assertSame('1', $this->generations->stamp($fresh, [Dimension::Theme]));
    }

    public function test_deleting_a_product_bumps_the_catalogue(): void
    {
        $tenant = $this->shop();
        $product = Product::factory()->create();

        $before = (int) $tenant->fresh()->page_gen_catalog;
        $product->delete();

        $this->assertGreaterThan($before, (int) $tenant->fresh()->page_gen_catalog);
    }

    public function test_saving_a_homepage_block_bumps_content(): void
    {
        $tenant = $this->shop();

        HomepageBlock::create([
            'type' => BlockType::Text,
            'position' => 1,
            'visible' => true,
            'payload' => ['html' => '<p>ahoj</p>'],
        ]);

        $this->assertSame('2', $this->generations->stamp($tenant->fresh(), [Dimension::Content]));
    }

    public function test_saving_the_theme_bumps_theme(): void
    {
        $tenant = $this->shop();

        TenantTheme::updateOrCreate(['tenant_id' => $tenant->id], ['primary_color' => '#112233']);

        $this->assertSame('2', $this->generations->stamp($tenant->fresh(), [Dimension::Theme]));
    }

    public function test_a_write_for_one_shop_does_not_bump_another(): void
    {
        $first = $this->shop();

        $second = Tenant::factory()->withDomain('druhy.droidshop')->create();
        $this->activateModule($second, 'products');

        Product::factory()->create();

        $this->assertSame('1', $this->generations->stamp($second->fresh(), [Dimension::Catalog]));
        $this->assertSame('2', $this->generations->stamp($first->fresh(), [Dimension::Catalog]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PageCacheInvalidationTest`
Expected: FAIL — první test hlásí `'1'` místo `'2'`

- [ ] **Step 3: Write the observer**

```php
<?php

namespace App\Core\PageCache;

use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * One observer for every model whose contents show up on a cached page.
 *
 * Instrumenting the writers instead was rejected: ProductWriter and
 * VariantWriter alone have more than fifteen writing methods and keep
 * growing, so the sixteenth would be the one nobody remembers. An observer
 * also covers write paths that do not exist yet — the CSV importer already
 * goes through the writers, and the next importer might not.
 *
 * The stock write-off is the deliberate exception: it updates through the
 * query builder (EloquentProductCatalog::decrementStock) and fires no
 * Eloquent event, so it bumps for itself. See wave 3.0 spec, decision 8.
 */
class PageCacheObserver
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Generations $generations,
    ) {}

    public function saved(Model $model): void
    {
        $this->bumpFor($model);
    }

    public function deleted(Model $model): void
    {
        $this->bumpFor($model);
    }

    private function bumpFor(Model $model): void
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            return;
        }

        $this->generations->bump($tenant, $this->dimensionFor($model));
    }

    private function dimensionFor(Model $model): Dimension
    {
        return match (true) {
            $model instanceof \App\Models\TenantTheme => Dimension::Theme,
            $model instanceof \Modules\Storefront\Models\HomepageBlock => Dimension::Content,
            $model instanceof \Modules\Pages\Models\Page => Dimension::Content,
            default => Dimension::Catalog,
        };
    }
}
```

- [ ] **Step 4: Register the observer**

V `app/Providers/AppServiceProvider.php` v `boot()` (moduly se resolvují stringem, aby jádro neimportovalo modulovou třídu — stejná konvence jako `ModuleRegistry` a `DefaultHomepage`):

```php
        // Page cache invalidation (wave 3.0). Registered on the class name as
        // a string: core knows a module by its key, never by a compile-time
        // import (same convention as DefaultHomepage in TenantProvisioner).
        foreach ([
            \App\Models\TenantTheme::class,
            'Modules\\Products\\Models\\Product',
            'Modules\\Products\\Models\\ProductVariant',
            'Modules\\Categories\\Models\\Category',
            'Modules\\Storefront\\Models\\HomepageBlock',
            'Modules\\Pages\\Models\\Page',
        ] as $model) {
            if (class_exists($model)) {
                $model::observe(\App\Core\PageCache\PageCacheObserver::class);
            }
        }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=PageCacheInvalidationTest`
Expected: PASS, 6 tests

- [ ] **Step 6: Run the full suite — observers touch every write path**

Run: `php artisan test`
Expected: PASS. Kdyby něco spadlo na počtu dotazů nebo na `page_gen_*`, oprav test, ne observer.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app/Core/PageCache app/Providers tests/Feature/PageCache
git add app/Core/PageCache app/Providers/AppServiceProvider.php tests/Feature/PageCache
git commit -m "feat(pagecache): bump generations from model observers"
```

---

### Task 7: Sklad zvedá generaci jen na hranici dostupnosti

**Files:**
- Modify: `Modules/Products/Services/EloquentProductCatalog.php:168` (`decrementStock`) a metoda `decrementVariantStock`
- Test: `tests/Feature/PageCache/StockBoundaryTest.php`

**Interfaces:**
- Consumes: `Generations::bump()`, `Dimension::Catalog`, `TenantContext::current()`
- Produces: žádné nové veřejné rozhraní — `decrementStock` po sobě uklidí sám

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\PageCache;

use App\Core\Catalog\Contracts\ProductCatalog;
use App\Core\PageCache\Dimension;
use App\Core\PageCache\Generations;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class StockBoundaryTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private Generations $generations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'products');
        app(TenantContext::class)->set($this->tenant);

        $this->generations = app(Generations::class);
    }

    private function catalogGeneration(): int
    {
        return (int) $this->tenant->fresh()->page_gen_catalog;
    }

    public function test_selling_a_unit_without_running_out_does_not_bump(): void
    {
        $product = Product::factory()->create(['stock_tracked' => true, 'stock_qty' => 5]);
        $before = $this->catalogGeneration();

        app(ProductCatalog::class)->decrementStock($product->id, 1);

        // 5 → 4 changes nothing a visitor can see: the detail page prints
        // availability, not the count. Bumping here would drop the whole
        // catalogue on every order and the cache would never hit.
        $this->assertSame($before, $this->catalogGeneration());
    }

    public function test_selling_the_last_unit_bumps(): void
    {
        $product = Product::factory()->create(['stock_tracked' => true, 'stock_qty' => 1]);
        $before = $this->catalogGeneration();

        app(ProductCatalog::class)->decrementStock($product->id, 1);

        $this->assertGreaterThan($before, $this->catalogGeneration());
    }

    public function test_an_untracked_product_never_bumps(): void
    {
        $product = Product::factory()->create(['stock_tracked' => false, 'stock_qty' => 0]);
        $before = $this->catalogGeneration();

        app(ProductCatalog::class)->decrementStock($product->id, 3);

        $this->assertSame($before, $this->catalogGeneration());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StockBoundaryTest`
Expected: FAIL — `test_selling_the_last_unit_bumps` neuvidí žádný posun

- [ ] **Step 3: Bump on the boundary in `decrementStock`**

V `Modules/Products/Services/EloquentProductCatalog.php` přidej do konstruktoru závislosti `TenantContext $context` a `Generations $generations` (nebo je resolvuj přes `app()`, pokud konstruktor spravuje registr) a na konec obou odpisových cest doplň:

```php
        // Page cache (wave 3.0): the write went through the query builder, so
        // no Eloquent event fired and the observer never saw it. Only the
        // in-stock/out-of-stock boundary is visible to a visitor — bumping on
        // every unit sold would drop the catalogue on every order.
        $this->bumpIfSoldOut($productId, $variantId);
```

a metodu:

```php
    private function bumpIfSoldOut(int $productId, ?int $variantId): void
    {
        $tenant = $this->context->current();

        if ($tenant === null) {
            return;
        }

        $remaining = $variantId === null
            ? (int) Product::query()->whereKey($productId)->value('stock_qty')
            : (int) ProductVariant::query()->whereKey($variantId)->value('stock_qty');

        if ($remaining > 0) {
            return;
        }

        $this->generations->bump($tenant, Dimension::Catalog);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=StockBoundaryTest`
Expected: PASS, 3 tests

- [ ] **Step 5: Run the order suite — the write-off runs inside order placement**

Run: `php artisan test --filter="Orders|Checkout"`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint Modules/Products/Services tests/Feature/PageCache
git add Modules/Products/Services/EloquentProductCatalog.php tests/Feature/PageCache
git commit -m "feat(pagecache): bump the catalogue only when stock crosses zero"
```

---

### Task 8: Nastavení a moduly zvedají vzhled

**Files:**
- Modify: `app/Core/Settings/SettingsService.php` — `set()` (řádek 58), `setMany()` (81), `forget()` (101)
- Modify: `app/Core/Modules/ModuleRegistry.php` — cesty aktivace a deaktivace (kolem `Cache::forget("modules:enabled:{$tenant->id}")`, řádek 160)
- Test: `tests/Feature/PageCache/SettingsInvalidationTest.php`

**Interfaces:**
- Consumes: `Generations::bump()`, `Dimension::Theme`
- Produces: žádné nové veřejné rozhraní

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\PageCache;

use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\SettingsService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class SettingsInvalidationTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'products');
        app(TenantContext::class)->set($this->tenant);
    }

    public function test_changing_a_module_setting_bumps_theme(): void
    {
        $before = (int) $this->tenant->fresh()->page_gen_theme;

        app(SettingsService::class)->setMany('products', ['variant_display' => 'select']);

        // Settings reach the rendered page (variant widget, order prefix,
        // minimum order) so a cached page must not survive the change.
        $this->assertGreaterThan($before, (int) $this->tenant->fresh()->page_gen_theme);
    }

    public function test_deactivating_a_module_bumps_theme(): void
    {
        $before = (int) $this->tenant->fresh()->page_gen_theme;

        app(ModuleRegistry::class)->deactivate($this->tenant, 'products');

        // The layout asks ShopModules what to render; a cached page still
        // showing a switched-off module's navigation would be a dead link.
        $this->assertGreaterThan($before, (int) $this->tenant->fresh()->page_gen_theme);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SettingsInvalidationTest`
Expected: FAIL — obě aserce vidí stejné číslo

- [ ] **Step 3: Bump in `SettingsService`**

Vedle každého existujícího `Cache::forget("settings:{$this->requireTenant()}:{$module}")` v `set()`, `setMany()` a `forget()` doplň:

```php
        // Page cache (wave 3.0): settings reach the rendered storefront, so
        // the stored HTML has to go with them.
        if (($tenant = app(TenantContext::class)->current()) !== null) {
            app(Generations::class)->bump($tenant, Dimension::Theme);
        }
```

- [ ] **Step 4: Bump in `ModuleRegistry`**

Ve stejném místě, kde `activate()` a `deactivate()` volají `Cache::forget("modules:enabled:{$tenant->id}")` (řádek 160), přidej:

```php
        // A cached page renders whatever ShopModules said at render time.
        app(Generations::class)->bump($tenant, Dimension::Theme);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=SettingsInvalidationTest`
Expected: PASS, 2 tests

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint app/Core/Settings app/Core/Modules tests/Feature/PageCache
git add app/Core/Settings/SettingsService.php app/Core/Modules/ModuleRegistry.php tests/Feature/PageCache
git commit -m "feat(pagecache): drop cached pages when settings or module set change"
```

---

### Task 9: Hledání — kratší TTL, jen dotazy s výsledky

**Files:**
- Modify: `Modules/Storefront/Http/Controllers/SearchController.php`
- Modify: `Modules/Storefront/routes/storefront.php:10`
- Modify: `app/Http/Middleware/CacheStorefrontPage.php` — TTL pro hledání
- Test: `tests/Feature/PageCache/SearchCacheTest.php`

**Interfaces:**
- Consumes: middleware `page-cache`
- Produces: `SearchController` nastavuje na odpovědi hlavičku `Cache-Control: private, no-store`, když dotaz nic nenašel nebo je delší než `pagecache.search_term_max` — tím ji politika z Tasku 3 sama odmítne uložit

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\PageCache;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class SearchCacheTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('pagecache.enabled', true);

        $this->artisan('modules:sync')->assertSuccessful();

        $tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($tenant, 'storefront');
        $this->activateModule($tenant, 'products');
    }

    private function storedKeys(): int
    {
        // The array store exposes its map; counting page: keys is the only
        // way to prove nothing was written.
        $store = Cache::store()->getStore();
        $all = (fn () => $this->storage)->call($store);

        return count(array_filter(array_keys($all), fn (string $k): bool => str_starts_with($k, 'page:')));
    }

    public function test_a_search_with_no_results_is_not_stored(): void
    {
        $before = $this->storedKeys();

        $this->get('http://obchod.droidshop/hledani?q=naprostoneexistujici')->assertOk();

        $this->assertSame($before, $this->storedKeys());
    }

    public function test_an_absurdly_long_term_is_not_stored(): void
    {
        $before = $this->storedKeys();

        $this->get('http://obchod.droidshop/hledani?q='.str_repeat('a', 200))->assertOk();

        $this->assertSame($before, $this->storedKeys());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SearchCacheTest`
Expected: FAIL — počet klíčů vzroste

- [ ] **Step 3: Mark uncacheable searches in the controller**

Na konci `SearchController::__invoke`, tam kde se vrací view, obal odpověď:

```php
        $response = response($view);

        // `?q=` has unbounded cardinality, so it is the obvious way to fill
        // the shared store on purpose. Only terms that actually found
        // something, and only short ones, are worth keeping.
        if ($results->isEmpty() || mb_strlen($term) > (int) config('pagecache.search_term_max', 60)) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
```

- [ ] **Step 4: Attach the middleware with the search TTL**

`Modules/Storefront/routes/storefront.php:10`:

```php
Route::get('/hledani', SearchController::class)
    ->middleware('page-cache:catalog,theme')
    ->name('search');
```

A v `CacheStorefrontPage::ttl()` rozšiř o kratší dobu pro hledání:

```php
    private function ttl(Response $response, Request $request): int
    {
        if (in_array($response->getStatusCode(), [404, 410], true)) {
            return (int) config('pagecache.ttl.not_found', 3600);
        }

        if ($request->query('q') !== null) {
            return (int) config('pagecache.ttl.search', 300);
        }

        return (int) config('pagecache.ttl.default', 600);
    }
```

Volání v `handle()` uprav na `$this->ttl($response, $request)`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter="SearchCacheTest|StorefrontSearchTest|PageCacheMiddlewareTest"`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint Modules/Storefront app/Http/Middleware tests/Feature/PageCache
git add Modules/Storefront app/Http/Middleware/CacheStorefrontPage.php tests/Feature/PageCache
git commit -m "feat(pagecache): cache only short searches that found something"
```

---

### Task 10: Sitemap a feedy pod generaci

**Files:**
- Modify: `Modules/Storefront/Http/Controllers/SitemapController.php:41`
- Modify: `Modules/Feeds/Http/Controllers/FeedController.php:44`
- Test: `tests/Feature/PageCache/XmlOutputInvalidationTest.php`

**Interfaces:**
- Consumes: `Generations::stamp()`, `Dimension::Catalog`, `TenantContext::current()`
- Produces: klíče obou XML výstupů nově nesou generaci katalogu

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\PageCache;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Products\Models\Product;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class XmlOutputInvalidationTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'storefront');
        $this->activateModule($this->tenant, 'products');
    }

    public function test_a_new_product_shows_up_in_the_sitemap_without_waiting_for_the_ttl(): void
    {
        $this->get('http://obchod.droidshop/sitemap.xml')->assertOk();

        app(\App\Core\Tenancy\TenantContext::class)->set($this->tenant);
        $product = Product::factory()->create(['name' => 'Novinka dne']);
        app(\App\Core\Tenancy\TenantContext::class)->forget();

        // Before wave 3.0 this held the stale document for a full hour.
        $this->get('http://obchod.droidshop/sitemap.xml')
            ->assertOk()
            ->assertSee($product->slug, escape: false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=XmlOutputInvalidationTest`
Expected: FAIL — sitemap nový slug neobsahuje

- [ ] **Step 3: Put the generation into both cache keys**

V `SitemapController` a `FeedController` uprav klíč předaný do `Cache::remember` tak, aby nesl razítko:

```php
        $tenant = app(TenantContext::class)->current();
        $stamp = app(Generations::class)->stamp($tenant, [Dimension::Catalog]);

        $xml = Cache::remember(
            'sitemap:'.$tenant->id.':'.$stamp,
            3600,
            fn (): string => $this->render(),
        );
```

Pro `FeedController` analogicky `'feed:'.$tenant->id.':'.$type.':'.$stamp`. Existující `Cache::forget('feed:'...)` ve `FeedAdminController:111` nech být — po změně klíče už netrefí nic, ale je neškodný a jeho odstranění patří k úklidu, ne k této změně chování.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="XmlOutputInvalidationTest|SitemapAndRobotsTest|Feeds"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint Modules/Storefront Modules/Feeds tests/Feature/PageCache
git add Modules/Storefront/Http/Controllers/SitemapController.php Modules/Feeds/Http/Controllers/FeedController.php tests/Feature/PageCache
git commit -m "feat(pagecache): invalidate sitemap and feeds with the catalogue"
```

---

### Task 11: Cache vyhledání redirectu (úleva na neexistujících URL)

**Files:**
- Modify: `app/Core/Routing/RedirectResponder.php:21`
- Test: `tests/Feature/PageCache/RedirectLookupCacheTest.php`

**Interfaces:**
- Consumes: `Generations::stamp()`, `Dimension::Catalog`
- Produces: výsledek hledání v `redirects` se cachuje pod klíčem `redirect:{tenant}:{stamp}:{path}`; „nic nenalezeno" se cachuje jako `''`, aby opakovaný sken nedělal dotaz pokaždé

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\PageCache;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class RedirectLookupCacheTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($tenant, 'storefront');
    }

    public function test_a_repeated_miss_does_not_query_the_redirect_table_again(): void
    {
        $this->get('http://obchod.droidshop/wp-admin/setup-config.php')->assertNotFound();

        $hits = 0;
        DB::listen(function ($query) use (&$hits): void {
            if (str_contains($query->sql, 'redirects')) {
                $hits++;
            }
        });

        $this->get('http://obchod.droidshop/wp-admin/setup-config.php')->assertNotFound();

        // Scanners hammer paths like this. The lookup is pure catalogue data,
        // so it belongs behind the same generation as everything else.
        $this->assertSame(0, $hits);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RedirectLookupCacheTest`
Expected: FAIL — `Failed asserting that 1 is identical to 0`

- [ ] **Step 3: Cache the lookup**

V `RedirectResponder::respond()` obal dotaz do `redirects`:

```php
        $stamp = $this->generations->stamp($tenant, [Dimension::Catalog]);

        // A redirect is born by renaming a slug, so it lives and dies with the
        // catalogue generation. The empty string stands for "no redirect" —
        // without it, every scanner request would query again.
        $target = Cache::remember(
            'redirect:'.$tenant->getKey().':'.$stamp.':'.$path,
            3600,
            fn (): string => (string) Redirect::query()
                ->where('from_path', $path)
                ->value('to_path'),
        );

        if ($target === '') {
            return null;
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="RedirectLookupCacheTest|StorefrontRedirectTest"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Core tests/Feature/PageCache
git add app/Core tests/Feature/PageCache
git commit -m "feat(pagecache): cache the redirect lookup behind the catalogue generation"
```

---

### Task 12: Tlačítko „Vymazat cache e-shopu"

**Files:**
- Modify: `routes/tenant.php` — nová routa za řádkem 24
- Modify: `app/Http/Controllers/Tenant/AppearanceController.php` — metoda `flushCache`
- Modify: `resources/js/Pages/Tenant/Appearance.vue`
- Test: `tests/Feature/PageCache/FlushCacheTest.php`

**Interfaces:**
- Consumes: `Generations::bumpAll()`
- Produces: routa `POST /admin/nastaveni/vzhled/cache` se jménem `admin.appearance.cache.flush`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\PageCache;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class FlushCacheTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        $this->activateModule($this->tenant, 'storefront');

        $this->owner = User::factory()->create();
        $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'joined_at' => now()]);
    }

    public function test_the_owner_can_flush_every_dimension(): void
    {
        $before = $this->tenant->fresh();

        $this->actingAs($this->owner)
            ->post('http://obchod.droidshop/admin/nastaveni/vzhled/cache')
            ->assertRedirect();

        $after = $this->tenant->fresh();

        $this->assertGreaterThan((int) $before->page_gen_catalog, (int) $after->page_gen_catalog);
        $this->assertGreaterThan((int) $before->page_gen_content, (int) $after->page_gen_content);
        $this->assertGreaterThan((int) $before->page_gen_theme, (int) $after->page_gen_theme);
    }

    public function test_a_stranger_cannot_flush_someone_elses_shop(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post('http://obchod.droidshop/admin/nastaveni/vzhled/cache')
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->post('http://obchod.droidshop/admin/nastaveni/vzhled/cache')
            ->assertRedirect();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FlushCacheTest`
Expected: FAIL — 404, routa neexistuje

- [ ] **Step 3: Add the route**

`routes/tenant.php`, za řádek 24:

```php
Route::post('/admin/nastaveni/vzhled/cache', [AppearanceController::class, 'flushCache'])
    ->name('admin.appearance.cache.flush');
```

- [ ] **Step 4: Add the controller method**

```php
    /**
     * Escape hatch for a tenant who changed something the invalidation did not
     * catch and does not want to wait out the TTL.
     */
    public function flushCache(Request $request): RedirectResponse
    {
        $tenant = app(TenantContext::class)->current();

        if ($tenant !== null) {
            app(Generations::class)->bumpAll($tenant);
        }

        return back()->with('success', 'Cache e-shopu byla vymazána.');
    }
```

- [ ] **Step 5: Add the button to the appearance page**

Do stránky vzhledu, do vlastní sekce pod barvy:

```vue
<section class="mt-8 border-t border-slate-200 pt-6">
    <h2 class="text-base font-semibold text-slate-900">Cache e-shopu</h2>
    <p class="mt-1 text-sm text-slate-600">
        Storefront se zobrazuje z uložené podoby, aby byl rychlý. Změny se projeví samy;
        tohle tlačítko je pro případ, kdy vidíte zastaralý obsah a nechcete čekat.
    </p>
    <button
        type="button"
        class="mt-3 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        @click="flushCache"
    >
        Vymazat cache e-shopu
    </button>
</section>
```

a v `<script setup>`:

```js
const flushCache = () => {
    router.post(route('admin.appearance.cache.flush'), {}, { preserveScroll: true })
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=FlushCacheTest`
Expected: PASS, 3 tests

- [ ] **Step 7: Build the frontend**

Run: `npm run build`
Expected: build projde bez chyby

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/Tenant routes/tenant.php tests/Feature/PageCache
git add app/Http/Controllers/Tenant routes/tenant.php resources/js/Pages tests/Feature/PageCache
git commit -m "feat(pagecache): let a tenant flush their own shop cache"
```

---

### Task 13: Akceptační test na skutečném detailu produktu

Tasky 1–12 testují každý díl zvlášť. Tenhle task ověřuje dvě akceptační kritéria specu, která díl po dílu prokázat nejde: že se z cachované stránky **skutečně dá nakoupit**, a že přihlášený zákazník nikdy nedostane hlavičku, kterou pro anonyma uložil někdo jiný.

**Files:**
- Test: `tests/Feature/PageCache/PageCacheAcceptanceTest.php`

**Interfaces:**
- Consumes: vše z Tasků 1–12; žádný produkční kód se nemění

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\PageCache;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Customers\Models\Customer;
use Modules\Products\Models\Product;
use Tests\Concerns\ActivatesModules;
use Tests\TestCase;

class PageCacheAcceptanceTest extends TestCase
{
    use ActivatesModules;
    use RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('tenancy.platform_domain', 'droidshop');
        config()->set('pagecache.enabled', true);

        $this->artisan('modules:sync')->assertSuccessful();

        $this->tenant = Tenant::factory()->withDomain('obchod.droidshop')->create();
        foreach (['storefront', 'products', 'categories', 'checkout', 'customers'] as $module) {
            $this->activateModule($this->tenant, $module);
        }

        app(\App\Core\Tenancy\TenantContext::class)->set($this->tenant);
        $this->product = Product::factory()->create([
            'name' => 'Testovací zboží',
            'stock_tracked' => true,
            'stock_qty' => 10,
        ]);
        app(\App\Core\Tenancy\TenantContext::class)->forget();
    }

    private function url(string $path = ''): string
    {
        return 'http://obchod.droidshop'.$path;
    }

    public function test_a_visitor_can_buy_from_a_page_someone_else_warmed(): void
    {
        // Visitor A warms the cache.
        $this->get($this->url('/produkt/'.$this->product->slug))->assertOk();
        $this->flushSession();

        // Visitor B is served the stored HTML and must still be able to post
        // the form in it — the CSRF token they receive is their own.
        $served = $this->get($this->url('/produkt/'.$this->product->slug))->assertOk()->getContent();
        $this->assertStringNotContainsString('@@PAGECACHE_CSRF@@', $served);

        preg_match('/name="_token"\s+value="([^"]+)"/', $served, $matches);
        $this->assertNotEmpty($matches[1] ?? '', 'the served page carries no usable CSRF token');

        $this->post($this->url('/kosik/pridat'), [
            '_token' => $matches[1],
            'product_id' => $this->product->id,
            'quantity' => 1,
        ])->assertRedirect();
    }

    public function test_a_signed_in_customer_never_sees_the_anonymous_header(): void
    {
        // Anonymous visit stores a page that says "Přihlásit se".
        $this->get($this->url('/produkt/'.$this->product->slug))->assertOk()->assertSee('Přihlásit se');

        app(\App\Core\Tenancy\TenantContext::class)->set($this->tenant);
        $customer = Customer::factory()->create();
        app(\App\Core\Tenancy\TenantContext::class)->forget();

        $this->actingAs($customer, 'customer')
            ->get($this->url('/produkt/'.$this->product->slug))
            ->assertOk()
            ->assertSee('Můj účet')
            ->assertDontSee('Přihlásit se');
    }

    public function test_a_price_change_shows_on_the_storefront_immediately(): void
    {
        $this->get($this->url('/produkt/'.$this->product->slug))->assertOk();

        app(\App\Core\Tenancy\TenantContext::class)->set($this->tenant);
        $this->product->update(['price' => 123400]);
        app(\App\Core\Tenancy\TenantContext::class)->forget();

        $this->get($this->url('/produkt/'.$this->product->slug))
            ->assertOk()
            ->assertSee('1 234');
    }
}
```

- [ ] **Step 2: Run the test**

Run: `php artisan test --filter=PageCacheAcceptanceTest`
Expected: PASS, 3 tests

Pokud `test_a_visitor_can_buy_from_a_page_someone_else_warmed` spadne na 419, je rozbitá záměna tokenu z Tasku 4 nebo middleware v Tasku 5 ukládá odpověď i s `Set-Cookie` — nedolaďuj test, oprav příčinu.

- [ ] **Step 3: Commit**

```bash
./vendor/bin/pint tests/Feature/PageCache
git add tests/Feature/PageCache
git commit -m "test(pagecache): prove a warmed page still sells and never leaks the header"
```

---

### Task 14: Dokumentace vlny

**Files:**
- Create: `docs/as-is/2026-08-03-page-cache.md`
- Modify: `docs/as-is/STATUS.md` — nový řádek v tabulce, úprava řádku o chybějící page cache v „Známá omezení"
- Modify: `CLAUDE.md` — sekce Rozhodnutí a odstavec o stavu vlny
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: hotová implementace z Tasků 1–12
- Produces: dokumentaci; žádný kód

- [ ] **Step 1: Run the whole suite and record the number**

Run: `php artisan test`
Expected: PASS; zapiš počet testů do as-is

- [ ] **Step 2: Write the as-is document**

Struktura podle `.claude/rules/as-is-on-milestone.md`: mapa změněných částí kódu, plnění spec §15.6 po bodech, testy (co běží, co chybí), **povinná sekce Odchylky od specifikace** (generace ve sloupcích místo cache, observery místo instrumentace writerů, 404 na neexistující cestě mimo dosah), technický dluh a pre-deploy checklist.

Technický dluh, který se musí objevit:
- etapa 2 (statický soubor) a její otevřená otázka CSRF
- etapa 3 (datová a fragmentová cache)
- `FeedAdminController:111` volá `Cache::forget` na klíč, který se po Tasku 10 už nepoužívá
- ruční vypnutí modulu zvedá `theme`, ne `content` — hrubší, než by muselo být

- [ ] **Step 3: Update STATUS.md**

Nový řádek do tabulky oblastí (**hotovo** / vlna 3.0 / odkaz na detail) a v sekci „Známá omezení" nahraď řádek *„Page cache podle §15.6 zatím není"* stavem po této vlně (aplikační vrstva hotová, statická vrstva čeká na nasazení).

- [ ] **Step 4: Update CLAUDE.md**

Do sekce Rozhodnutí přidej datované záznamy odpovídající rozhodnutím 1–10 ze specu, ve stejném hutném tvaru jako okolní řádky. Do odstavce o stavu doplň větu o uzavření vlny 3.0.

- [ ] **Step 5: Update CHANGELOG.md a VERSION**

Použij skill `versioning` — minor bump.

- [ ] **Step 6: Commit**

```bash
git add docs CLAUDE.md CHANGELOG.md VERSION
git commit -m "docs: close wave 3.0 page cache"
```

---

## Poznámka k pořadí

Tasky 1–5 na sebe navazují a musí jít v pořadí. Tasky 6–11 jsou na sobě nezávislé (každý sahá na jinou část kódu) a smějí běžet paralelně. Task 12 vyžaduje Task 1. Task 13 vyžaduje Tasky 1–6. Task 14 vyžaduje všechny.
