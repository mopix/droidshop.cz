# Bloková homepage (vlna 2.3) — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nájemce poskládá homepage e-shopu z bloků (hero, řada produktů, grid kategorií, text, banner) v adminu; storefront homepage je renderovaná ze bloků čistým Blade SSR.

**Architecture:** Relační tabulka `homepage_blocks` (řádek per blok, řazení `position`, per-typ JSON payload) v modulu `storefront`. `HomeController` iteruje viditelné bloky a includuje Blade komponentu per typ. Admin editor = Inertia stránka za modulovou admin routou. Seed výchozích bloků v `TenantProvisioner` (noví) + backfill migrace (stávající).

**Tech Stack:** Laravel 13, PHP 8.3, Blade SSR (storefront), Vue 3 + Inertia (admin), Pest/PHPUnit, MySQL/SQLite (testy), `nwidart/laravel-modules`.

## Global Constraints

- Storefront homepage **Blade SSR, žádný nový JS** (`.claude/rules/storefront-rendering.md`). Admin editor smí být Inertia.
- Vše v modulu `storefront` (core modul). Modulové admin routy: prefix `admin/m/storefront`, name `admin.storefront.*`, middleware `['web','module:storefront','tenant.member']` (registruje `ModuleRouteRegistrar`).
- Inertia stránky modulů žijí v `resources/js/Pages/Modules/Storefront/` (view finder je jinde nenajde — rozhodnutí 2026-07-20).
- Tenant izolace: model `BelongsToTenant` (global scope + auto-fill `tenant_id`).
- Text HTML sanitizace **při zápisu** přes `App\Core\Html\HtmlSanitizer::clean(?string): ?string`.
- Obrázky **raster-only** (`png,jpg,jpeg,webp`, žádné SVG) přes `App\Core\Storage\FileStorage` (`putPublic`, `publicUrl`, `delete`).
- URL v payloadu: jen relativní `/…` nebo absolutní `http(s)://`; odmítnout `javascript:`/`data:`/`vbscript:`/bezschemé.
- Mazací akce = potvrzovací dialog (admin UI).
- Write-freeze: `CheckTenantStatus` blokuje POST/PATCH/DELETE na suspended/past_due (platí automaticky na `tenant.member` skupině).
- Strop 30 bloků / homepage (FormRequest).
- **Page cache zatím NENÍ implementovaná** (jen budoucí `bootstrap/app.php` komentář) → žádný invalidační task; až přijde, doplní se hook.
- Commity: anglicky, `feat:`/`test:`/`docs:`, ukončené `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- Před commitem PHP: `./vendor/bin/pint` dirty files.
- Testy: `php artisan test --compact` (dotčená oblast).

---

## File Structure

**Modul `storefront`:**
- `Modules/Storefront/Database/Migrations/xxxx_create_homepage_blocks_table.php` — schéma.
- `Modules/Storefront/Database/Migrations/xxxx_seed_default_homepage_blocks.php` — backfill stávajících tenantů.
- `Modules/Storefront/Models/HomepageBlock.php` — model (`BelongsToTenant`).
- `Modules/Storefront/Enums/BlockType.php` — enum typů + default payload.
- `Modules/Storefront/Support/DefaultHomepage.php` — seed služba (jedna pravda).
- `Modules/Storefront/Support/BlockUrl.php` — validace/normalizace URL.
- `Modules/Storefront/Http/Controllers/HomeController.php` — **modify** (render z bloků).
- `Modules/Storefront/Http/Controllers/HomepageAdminController.php` — admin CRUD.
- `Modules/Storefront/Http/Requests/StoreBlockRequest.php`, `UpdateBlockRequest.php`, `MoveBlockRequest.php`, `ToggleBlockRequest.php` — validace.
- `Modules/Storefront/routes/admin.php` — **create** (dnes neexistuje).
- `Modules/Storefront/module.json` — **modify** (`nav` + `permissions`).
- `Modules/Storefront/Resources/views/home.blade.php` — **modify** (iterace bloků).
- `Modules/Storefront/Resources/views/components/blocks/{hero,product-row,category-grid,text,banner}.blade.php` — **create** render partials.

**Core:**
- `app/Core/Tenancy/TenantProvisioner.php` — **modify** (volání `DefaultHomepage`).

**Admin (core strom):**
- `resources/js/Pages/Modules/Storefront/Homepage.vue` — editor.

**Testy:**
- `tests/Feature/Storefront/HomepageBlocksRenderTest.php`
- `tests/Feature/Storefront/HomepageAdminTest.php`
- `tests/Feature/Storefront/HomepageSeedTest.php`
- `tests/Unit/Storefront/BlockUrlTest.php`

---

## Task 1: Migrace, model `HomepageBlock`, enum `BlockType`

**Files:**
- Create: `Modules/Storefront/Database/Migrations/2026_07_26_000001_create_homepage_blocks_table.php`
- Create: `Modules/Storefront/Models/HomepageBlock.php`
- Create: `Modules/Storefront/Enums/BlockType.php`
- Test: `tests/Feature/Storefront/HomepageSeedTest.php` (zde jen model/scope část)

**Interfaces:**
- Produces: `HomepageBlock` model s `$casts` (`payload`→`array`, `visible`→`bool`, `position`→`int`), scope `visible()`, statická factory. Enum `BlockType` s cases `Hero,ProductRow,CategoryGrid,Text,Banner` (backed string `hero,product_row,category_grid,text,banner`) a metodou `defaultPayload(): array`.

- [ ] **Step 1: Napiš migraci**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedInteger('position')->default(0);
            $table->string('type', 32);
            $table->json('payload');
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_blocks');
    }
};
```

- [ ] **Step 2: Napiš enum `BlockType`**

```php
<?php

namespace Modules\Storefront\Enums;

enum BlockType: string
{
    case Hero = 'hero';
    case ProductRow = 'product_row';
    case CategoryGrid = 'category_grid';
    case Text = 'text';
    case Banner = 'banner';

    /** Prázdný/výchozí payload pro nově přidaný blok tohoto typu. */
    public function defaultPayload(): array
    {
        return match ($this) {
            self::Hero => ['title' => '', 'subtitle' => null, 'cta_label' => null, 'cta_url' => null, 'image_path' => null],
            self::ProductRow => ['heading' => 'Novinky', 'mode' => 'latest', 'count' => 8, 'product_ids' => []],
            self::CategoryGrid => ['heading' => 'Kategorie', 'category_ids' => []],
            self::Text => ['heading' => null, 'html' => ''],
            self::Banner => ['image_path' => null, 'url' => null, 'alt' => ''],
        };
    }
}
```

- [ ] **Step 3: Napiš model `HomepageBlock`**

```php
<?php

namespace Modules\Storefront\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Storefront\Enums\BlockType;

class HomepageBlock extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => BlockType::class,
            'payload' => 'array',
            'visible' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('visible', true);
    }
}
```

- [ ] **Step 4: Napiš test scope + tenant izolace**

```php
// tests/Feature/Storefront/HomepageSeedTest.php
<?php

use App\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;

it('scopes homepage blocks to the current tenant', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    app(TenantContext::class)->runAs($a, fn () => HomepageBlock::create([
        'position' => 0, 'type' => BlockType::Text, 'payload' => ['html' => 'A'], 'visible' => true,
    ]));

    $seenByB = app(TenantContext::class)->runAs($b, fn () => HomepageBlock::count());
    $seenByA = app(TenantContext::class)->runAs($a, fn () => HomepageBlock::count());

    expect($seenByB)->toBe(0);
    expect($seenByA)->toBe(1);
});
```

- [ ] **Step 5: Spusť test — nejdřív ověř fail, pak pass**

Run: `php artisan test --compact --filter=HomepageSeedTest`
Expected: nejdřív FAIL (chybí tabulka/model), po krocích 1–3 PASS.

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint Modules/Storefront/Models/HomepageBlock.php Modules/Storefront/Enums/BlockType.php
git add Modules/Storefront/Database/Migrations Modules/Storefront/Models Modules/Storefront/Enums tests/Feature/Storefront/HomepageSeedTest.php
git commit -m "feat: homepage_blocks table, model and block-type enum

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Seed služba `DefaultHomepage` + zapojení do `TenantProvisioner`

**Files:**
- Create: `Modules/Storefront/Support/DefaultHomepage.php`
- Modify: `app/Core/Tenancy/TenantProvisioner.php` (uvnitř transakce, po aktivaci modulů)
- Test: `tests/Feature/Storefront/HomepageSeedTest.php` (rozšíření)

**Interfaces:**
- Consumes: `HomepageBlock` (Task 1).
- Produces: `DefaultHomepage::seed(Tenant $tenant): void` — idempotentní (no-op, když tenant už má bloky). Zakládá 3 bloky: hero (title = název e-shopu), product_row (latest 8, „Novinky"), category_grid (všechny top-level, „Kategorie").

- [ ] **Step 1: Napiš `DefaultHomepage`**

```php
<?php

namespace Modules\Storefront\Support;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;

/**
 * The one recipe for a shop's starting homepage. Called by TenantProvisioner
 * for new tenants and by the backfill migration for existing ones — never
 * duplicated. Idempotent: a tenant that already has blocks is left untouched.
 */
class DefaultHomepage
{
    public function __construct(private readonly TenantContext $context) {}

    public function seed(Tenant $tenant): void
    {
        $this->context->runAs($tenant, function () use ($tenant): void {
            if (HomepageBlock::query()->exists()) {
                return;
            }

            HomepageBlock::create([
                'position' => 0,
                'type' => BlockType::Hero,
                'payload' => [
                    'title' => $tenant->name,
                    'subtitle' => 'Vítejte v našem e-shopu. Podívejte se na aktuální nabídku.',
                    'cta_label' => null, 'cta_url' => null, 'image_path' => null,
                ],
                'visible' => true,
            ]);

            HomepageBlock::create([
                'position' => 1,
                'type' => BlockType::ProductRow,
                'payload' => ['heading' => 'Novinky', 'mode' => 'latest', 'count' => 8, 'product_ids' => []],
                'visible' => true,
            ]);

            HomepageBlock::create([
                'position' => 2,
                'type' => BlockType::CategoryGrid,
                'payload' => ['heading' => 'Kategorie', 'category_ids' => []],
                'visible' => true,
            ]);
        });
    }
}
```

- [ ] **Step 2: Zapoj do `TenantProvisioner`**

V `app/Core/Tenancy/TenantProvisioner.php`, uvnitř `DB::transaction`, **po** smyčce `foreach ($this->modulesFor($plan) …)` a **před** `audit->log`, přidej:

```php
app(\Modules\Storefront\Support\DefaultHomepage::class)->seed($tenant);
```

(Přes `app()`, ne konstruktor injektáž — core nesmí typově znát modul; `DefaultHomepage` je bezpečné resolvovat runtime protože `storefront` je core modul vždy aktivní.)

- [ ] **Step 3: Napiš test seedu při provisioningu**

```php
it('seeds a default homepage when a tenant is provisioned', function () {
    $owner = App\Models\User::factory()->create();
    $plan = App\Models\Plan::factory()->create();

    $tenant = app(App\Core\Tenancy\TenantProvisioner::class)
        ->provision($owner, 'Test Shop', 'testshop', $plan);

    $blocks = app(App\Core\Tenancy\TenantContext::class)
        ->runAs($tenant, fn () => Modules\Storefront\Models\HomepageBlock::orderBy('position')->pluck('type'));

    expect($blocks->map->value->all())->toBe(['hero', 'product_row', 'category_grid']);
});

it('does not seed twice', function () {
    $tenant = App\Models\Tenant::factory()->create();
    $seeder = app(Modules\Storefront\Support\DefaultHomepage::class);
    $seeder->seed($tenant);
    $seeder->seed($tenant);

    $count = app(App\Core\Tenancy\TenantContext::class)
        ->runAs($tenant, fn () => Modules\Storefront\Models\HomepageBlock::count());
    expect($count)->toBe(3);
});
```

- [ ] **Step 4: Spusť testy**

Run: `php artisan test --compact --filter=HomepageSeedTest`
Expected: PASS (všechny 3+).

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint Modules/Storefront/Support/DefaultHomepage.php app/Core/Tenancy/TenantProvisioner.php
git add Modules/Storefront/Support/DefaultHomepage.php app/Core/Tenancy/TenantProvisioner.php tests/Feature/Storefront/HomepageSeedTest.php
git commit -m "feat: seed default homepage on tenant provisioning

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Backfill migrace pro stávající tenanty

**Files:**
- Create: `Modules/Storefront/Database/Migrations/2026_07_26_000002_seed_default_homepage_blocks.php`
- Test: `tests/Feature/Storefront/HomepageSeedTest.php` (rozšíření)

**Interfaces:**
- Consumes: `DefaultHomepage::seed` (Task 2).

- [ ] **Step 1: Napiš backfill migraci**

```php
<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Modules\Storefront\Support\DefaultHomepage;

return new class extends Migration
{
    public function up(): void
    {
        $seeder = app(DefaultHomepage::class);

        Tenant::query()->each(function (Tenant $tenant) use ($seeder): void {
            $seeder->seed($tenant); // idempotent: přeskočí tenanty s bloky
        });
    }

    public function down(): void
    {
        // Seed data — nevrací se; smazání by zahodilo i ručně upravené bloky.
    }
};
```

- [ ] **Step 2: Napiš test idempotence backfillu**

```php
it('backfills only tenants without blocks', function () {
    $fresh = App\Models\Tenant::factory()->create();
    $edited = App\Models\Tenant::factory()->create();

    // edited už má 1 vlastní blok
    app(App\Core\Tenancy\TenantContext::class)->runAs($edited, fn () =>
        Modules\Storefront\Models\HomepageBlock::create([
            'position' => 0, 'type' => Modules\Storefront\Enums\BlockType::Text,
            'payload' => ['html' => 'custom'], 'visible' => true,
        ]));

    app(Modules\Storefront\Support\DefaultHomepage::class)->seed($fresh);
    app(Modules\Storefront\Support\DefaultHomepage::class)->seed($edited);

    $ctx = app(App\Core\Tenancy\TenantContext::class);
    expect($ctx->runAs($fresh, fn () => Modules\Storefront\Models\HomepageBlock::count()))->toBe(3);
    expect($ctx->runAs($edited, fn () => Modules\Storefront\Models\HomepageBlock::count()))->toBe(1);
});
```

- [ ] **Step 3: Spusť test + migraci lokálně**

Run: `php artisan test --compact --filter=HomepageSeedTest`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add Modules/Storefront/Database/Migrations tests/Feature/Storefront/HomepageSeedTest.php
git commit -m "feat: backfill default homepage for existing tenants

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Storefront render z bloků

**Files:**
- Modify: `Modules/Storefront/Http/Controllers/HomeController.php`
- Modify: `Modules/Storefront/Resources/views/home.blade.php`
- Create: `Modules/Storefront/Resources/views/components/blocks/hero.blade.php`
- Create: `Modules/Storefront/Resources/views/components/blocks/product-row.blade.php`
- Create: `Modules/Storefront/Resources/views/components/blocks/category-grid.blade.php`
- Create: `Modules/Storefront/Resources/views/components/blocks/text.blade.php`
- Create: `Modules/Storefront/Resources/views/components/blocks/banner.blade.php`
- Test: `tests/Feature/Storefront/HomepageBlocksRenderTest.php`

**Interfaces:**
- Consumes: `HomepageBlock::visible()` (Task 1), `ProductCatalog::latest`/`findById`, `ShopModules::has`, `Category::visible()`.
- Produces: každý blok předrenderovaný do struktury `['type' => string, 'view_data' => array]`, kterou home.blade includuje.

- [ ] **Step 1: Napiš render test (nejdřív fail)**

```php
// tests/Feature/Storefront/HomepageBlocksRenderTest.php
<?php

use App\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;

function seedBlocks(Tenant $tenant, array $blocks): void {
    app(TenantContext::class)->runAs($tenant, function () use ($blocks) {
        foreach ($blocks as $i => $b) {
            HomepageBlock::create(['position' => $i, 'type' => $b['type'], 'payload' => $b['payload'], 'visible' => $b['visible'] ?? true]);
        }
    });
}

it('renders a text block into raw HTML (no JS needed)', function () {
    $tenant = /* provisioned tenant on its host — use existing storefront test helper */ makeShopTenant();
    seedBlocks($tenant, [['type' => BlockType::Text, 'payload' => ['heading' => 'O nás', 'html' => '<p>Ahoj</p>']]]);

    $this->get(shopUrl($tenant, '/'))
        ->assertOk()
        ->assertSee('O nás')
        ->assertSee('Ahoj');
});

it('does not render hidden blocks', function () {
    $tenant = makeShopTenant();
    seedBlocks($tenant, [['type' => BlockType::Text, 'payload' => ['html' => 'SKRYTO'], 'visible' => false]]);

    $this->get(shopUrl($tenant, '/'))->assertOk()->assertDontSee('SKRYTO');
});
```

> Helpery `makeShopTenant()` / `shopUrl()` / `seedBlocks()`: pokud v `tests/Feature/Storefront/` existuje pattern pro nastavení tenant hostu (viz `StorefrontCatalogTest.php`), použij ten. Jinak vytvoř `tests/Feature/Storefront/StorefrontTestHelpers.php` s tenant provision + `Host` hlavičkou.

- [ ] **Step 2: Přepiš `HomeController::render`**

```php
public function render(Request $request): View
{
    $tenant = $this->context->current();

    $blocks = HomepageBlock::query()->visible()->orderBy('position')->get()
        ->map(fn (HomepageBlock $block) => $this->prepare($block))
        ->filter()   // vypadlé (modul off) → null → pryč
        ->values();

    return view('storefront::home', [
        'shopName' => $tenant?->name ?? config('app.name'),
        'blocks' => $blocks,
        'seo' => new Seo(
            title: $tenant?->name ?? config('app.name'),
            description: 'Nakupujte v e-shopu '.($tenant?->name ?? config('app.name')).'.',
            canonical: Seo::canonicalFor('/'),
        ),
    ]);
}

/** @return array{type:string, data:array}|null */
private function prepare(HomepageBlock $block): ?array
{
    return match ($block->type) {
        BlockType::Hero => ['type' => 'hero', 'data' => $block->payload],
        BlockType::Text => ['type' => 'text', 'data' => $block->payload],
        BlockType::Banner => ['type' => 'banner', 'data' => $block->payload],
        BlockType::ProductRow => $this->modules->has('products')
            ? ['type' => 'product-row', 'data' => ['heading' => $block->payload['heading'] ?? null, 'products' => $this->rowProducts($block->payload)]]
            : null,
        BlockType::CategoryGrid => $this->modules->has('categories')
            ? ['type' => 'category-grid', 'data' => ['heading' => $block->payload['heading'] ?? null, 'categories' => $this->gridCategories($block->payload)]]
            : null,
    };
}

private function rowProducts(array $payload): \Illuminate\Support\Collection
{
    if (($payload['mode'] ?? 'latest') === 'manual') {
        return collect($payload['product_ids'] ?? [])
            ->map(fn ($id) => $this->catalog->findById((int) $id))
            ->filter()   // zmizelý/skrytý produkt pryč
            ->values();
    }

    return $this->catalog->latest((int) ($payload['count'] ?? 8));
}

private function gridCategories(array $payload): \Illuminate\Support\Collection
{
    $ids = $payload['category_ids'] ?? [];
    $query = \Modules\Categories\Models\Category::query()->visible();

    return empty($ids)
        ? $query->whereNull('parent_id')->orderBy('position')->get()
        : $query->whereIn('id', $ids)->orderBy('position')->get();
}
```

Doplň `use` pro `HomepageBlock`, `BlockType`. Odstraň staré props `products`/`categories`.

- [ ] **Step 3: Přepiš `home.blade.php` na iteraci**

```blade
@extends('storefront::layouts.shop')

@section('content')
    @forelse ($blocks as $block)
        @includeFirst(
            ['storefront::components.blocks.'.$block['type']],
            $block['data']
        )
    @empty
        <p class="text-slate-600">Nabídka se právě připravuje.</p>
    @endforelse
@endsection

@push('head')
    <x-storefront::json-ld :data="[
        '@context' => 'https://schema.org', '@type' => 'Organization',
        'name' => $shopName, 'url' => url('/'),
    ]" />
    <x-storefront::json-ld :data="[
        '@context' => 'https://schema.org', '@type' => 'WebSite',
        'name' => $shopName, 'url' => url('/'),
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => url('/hledani').'?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ]" />
@endpush
```

> `@includeFirst` s `blocks.{type}` view: data pole se rozbalí do proměnných partialu.

- [ ] **Step 4: Napiš 5 render partialů**

`components/blocks/hero.blade.php`:
```blade
@props([])
<section class="border-b border-slate-100 pb-10">
    <h1 class="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">{{ $title }}</h1>
    @if (!empty($subtitle))
        <p class="mt-3 max-w-2xl text-slate-600">{{ $subtitle }}</p>
    @endif
    @if (!empty($cta_label) && !empty($cta_url))
        <a href="{{ $cta_url }}" class="btn btn-primary mt-6 inline-block">{{ $cta_label }}</a>
    @endif
</section>
```

`components/blocks/text.blade.php`:
```blade
<section class="prose max-w-none py-8">
    @if (!empty($heading))<h2 class="text-lg font-semibold text-slate-900">{{ $heading }}</h2>@endif
    {!! $html !!}  {{-- sanitizováno při zápisu --}}
</section>
```

`components/blocks/product-row.blade.php`:
```blade
<section class="py-8" @if(!empty($heading)) aria-labelledby="row-heading" @endif>
    @if (!empty($heading))<h2 id="row-heading" class="mb-6 text-lg font-semibold text-slate-900">{{ $heading }}</h2>@endif
    @if ($products->isEmpty())
        <p class="text-slate-600">Nabídka se právě připravuje.</p>
    @else
        <x-storefront::product-grid :products="$products" />
    @endif
</section>
```

`components/blocks/category-grid.blade.php`:
```blade
<section class="py-8">
    @if (!empty($heading))<h2 class="mb-4 text-lg font-semibold text-slate-900">{{ $heading }}</h2>@endif
    <ul class="flex flex-wrap gap-3">
        @foreach ($categories as $category)
            <li><a href="{{ $category->url() }}" class="btn btn-outline">{{ $category->name }}</a></li>
        @endforeach
    </ul>
</section>
```

`components/blocks/banner.blade.php`:
```blade
<section class="py-8">
    @php($src = \App\Core\Storage\FileStorage::class)
    @if (!empty($image_path))
        @php($url = app($src)->publicUrl($image_path))
        @if (!empty($url))
            @if (!empty($url) && !empty($url))
            @endif
        @endif
        <a href="{{ $url ?? '#' }}"></a>
    @endif
</section>
```
> Banner partial: renderuj `<img src="{{ app(FileStorage::class)->publicUrl($image_path) }}" alt="{{ $alt }}" loading="lazy" class="w-full rounded-lg">`, obalený `<a href="{{ $url }}">` jen když `$url` neprázdné. (Zjednoduš oproti scaffoldu výše — cílem je jeden `<img>` s `alt` a volitelným odkazem.)

- [ ] **Step 5: Spusť render testy**

Run: `php artisan test --compact --filter=HomepageBlocksRenderTest`
Expected: PASS. Přidej i test `product_row` bez modulu `products` (blok se vynechá, stránka `assertOk`).

- [ ] **Step 6: Ověř bez JS ručně (nepovinné lokálně)**

Run: `curl -s -H "Host: <shop-host>" http://127.0.0.1:8000/ | grep -i 'novinky\|kategorie'`
Expected: obsah bloků v surovém HTML.

- [ ] **Step 7: Pint + commit**

```bash
./vendor/bin/pint Modules/Storefront/Http/Controllers/HomeController.php
git add Modules/Storefront/Http/Controllers/HomeController.php Modules/Storefront/Resources/views tests/Feature/Storefront/HomepageBlocksRenderTest.php
git commit -m "feat: render storefront homepage from blocks

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: URL validace (`BlockUrl`) + FormRequesty

**Files:**
- Create: `Modules/Storefront/Support/BlockUrl.php`
- Create: `Modules/Storefront/Http/Requests/StoreBlockRequest.php`
- Create: `Modules/Storefront/Http/Requests/UpdateBlockRequest.php`
- Create: `Modules/Storefront/Http/Requests/MoveBlockRequest.php`
- Create: `Modules/Storefront/Http/Requests/ToggleBlockRequest.php`
- Test: `tests/Unit/Storefront/BlockUrlTest.php`

**Interfaces:**
- Produces: `BlockUrl::isSafe(?string $url): bool` (null/prázdné = true; jinak `/…` nebo `http(s)://…`, odmítni `javascript:`/`data:`/`vbscript:`/bezschemé s dvojtečkou). `UpdateBlockRequest::sanitizedPayload(BlockType, HtmlSanitizer): array` — vrátí validovaný+očištěný payload per typ.

- [ ] **Step 1: Napiš `BlockUrlTest`**

```php
<?php

use Modules\Storefront\Support\BlockUrl;

it('accepts internal and http(s) urls', function () {
    expect(BlockUrl::isSafe(null))->toBeTrue();
    expect(BlockUrl::isSafe(''))->toBeTrue();
    expect(BlockUrl::isSafe('/kategorie/boty'))->toBeTrue();
    expect(BlockUrl::isSafe('https://example.com'))->toBeTrue();
    expect(BlockUrl::isSafe('http://example.com/x'))->toBeTrue();
});

it('rejects dangerous schemes', function () {
    expect(BlockUrl::isSafe('javascript:alert(1)'))->toBeFalse();
    expect(BlockUrl::isSafe('data:text/html,x'))->toBeFalse();
    expect(BlockUrl::isSafe('vbscript:x'))->toBeFalse();
    expect(BlockUrl::isSafe('ftp://x'))->toBeFalse();
    expect(BlockUrl::isSafe('mailto:x'))->toBeFalse();
});
```

- [ ] **Step 2: Napiš `BlockUrl`**

```php
<?php

namespace Modules\Storefront\Support;

class BlockUrl
{
    public static function isSafe(?string $url): bool
    {
        if ($url === null || $url === '') {
            return true;
        }
        if (str_starts_with($url, '/')) {
            return true; // interní relativní cesta
        }
        return (bool) preg_match('#^https?://#i', $url);
    }
}
```

- [ ] **Step 3: Spusť `BlockUrlTest`**

Run: `php artisan test --compact --filter=BlockUrlTest`
Expected: FAIL → (po Step 2) PASS.

- [ ] **Step 4: Napiš `StoreBlockRequest`** (jen typ, prázdný payload z enumu)

```php
<?php

namespace Modules\Storefront\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Storefront\Enums\BlockType;

class StoreBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('storefront.homepage.manage') ?? false;
    }

    public function rules(): array
    {
        return ['type' => ['required', Rule::enum(BlockType::class)]];
    }

    public function blockType(): BlockType
    {
        return BlockType::from($this->validated('type'));
    }
}
```

- [ ] **Step 5: Napiš `UpdateBlockRequest`** (payload validace + sanitizace per typ)

```php
<?php

namespace Modules\Storefront\Http\Requests;

use App\Core\Html\HtmlSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Models\HomepageBlock;
use Modules\Storefront\Support\BlockUrl;

class UpdateBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('storefront.homepage.manage') ?? false;
    }

    public function rules(): array
    {
        // Volný JSON payload; tvar hlídá per-typ validace níže + withValidator.
        return [
            'payload' => ['required', 'array'],
            'visible' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $type = $this->route('block')->type;
            $p = $this->input('payload', []);
            foreach ($this->urlFields($type) as $field) {
                if (isset($p[$field]) && ! BlockUrl::isSafe($p[$field])) {
                    $v->errors()->add("payload.$field", 'Neplatná nebo nebezpečná adresa.');
                }
            }
            if ($type === BlockType::ProductRow && ($p['mode'] ?? 'latest') === 'latest') {
                $count = (int) ($p['count'] ?? 0);
                if ($count < 1 || $count > 12) {
                    $v->errors()->add('payload.count', 'Počet 1–12.');
                }
            }
        });
    }

    /** @return list<string> */
    private function urlFields(BlockType $type): array
    {
        return match ($type) {
            BlockType::Hero => ['cta_url'],
            BlockType::Banner => ['url'],
            default => [],
        };
    }

    /** Validovaný + očištěný payload, připravený k uložení. */
    public function cleanPayload(BlockType $type, HtmlSanitizer $sanitizer): array
    {
        $p = $this->validated('payload');
        if ($type === BlockType::Text && isset($p['html'])) {
            $p['html'] = $sanitizer->clean($p['html']);
        }
        return $p;
    }
}
```

- [ ] **Step 6: Napiš `MoveBlockRequest` + `ToggleBlockRequest`**

```php
// MoveBlockRequest
public function authorize(): bool { return $this->user()?->can('storefront.homepage.manage') ?? false; }
public function rules(): array { return ['direction' => ['required', 'in:up,down']]; }

// ToggleBlockRequest
public function authorize(): bool { return $this->user()?->can('storefront.homepage.manage') ?? false; }
public function rules(): array { return ['visible' => ['required', 'boolean']]; }
```

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint Modules/Storefront/Support/BlockUrl.php Modules/Storefront/Http/Requests
git add Modules/Storefront/Support/BlockUrl.php Modules/Storefront/Http/Requests tests/Unit/Storefront/BlockUrlTest.php
git commit -m "feat: block url guard and form requests

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Admin controller, routy, manifest (nav + permission)

**Files:**
- Create: `Modules/Storefront/Http/Controllers/HomepageAdminController.php`
- Create: `Modules/Storefront/routes/admin.php`
- Modify: `Modules/Storefront/module.json` (`nav` + `permissions`)
- Test: `tests/Feature/Storefront/HomepageAdminTest.php`

**Interfaces:**
- Consumes: FormRequesty (Task 5), `HomepageBlock`, `BlockType`, `FileStorage`, `HtmlSanitizer`.
- Produkuje routy: `admin.storefront.homepage.{index,store,update,move,toggle,destroy}`.

- [ ] **Step 1: Přidej permission + nav do `module.json`**

```json
"permissions": ["storefront.homepage.manage"],
"nav": [
    { "area": "admin", "label": "Homepage", "route": "admin.storefront.homepage.index", "icon": "layout", "order": 40 }
]
```
> Ověř, jak `TenantPermissions` čte manifest `permissions` a přiřazuje owner/staff (viz `app/Core/Modules/TenantPermissions.php`). Owner musí dostat `storefront.homepage.manage` automaticky.

- [ ] **Step 2: Napiš `routes/admin.php`**

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Storefront\Http\Controllers\HomepageAdminController;

Route::prefix('homepage')->name('homepage.')->group(function () {
    Route::get('/', [HomepageAdminController::class, 'index'])->name('index');
    Route::post('/blok', [HomepageAdminController::class, 'store'])->name('store');
    Route::patch('/blok/{block}', [HomepageAdminController::class, 'update'])->name('update');
    Route::patch('/blok/{block}/presun', [HomepageAdminController::class, 'move'])->name('move');
    Route::patch('/blok/{block}/viditelnost', [HomepageAdminController::class, 'toggle'])->name('toggle');
    Route::delete('/blok/{block}', [HomepageAdminController::class, 'destroy'])->name('destroy');
});
```
> Plná jména rout: `admin.storefront.homepage.*` (prefix `admin.storefront.` z `ModuleRouteRegistrar`).

- [ ] **Step 3: Napiš `HomepageAdminController`**

```php
<?php

namespace Modules\Storefront\Http\Controllers;

use App\Core\Html\HtmlSanitizer;
use App\Core\Storage\FileStorage;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;
use Modules\Storefront\Enums\BlockType;
use Modules\Storefront\Http\Requests\MoveBlockRequest;
use Modules\Storefront\Http\Requests\StoreBlockRequest;
use Modules\Storefront\Http\Requests\ToggleBlockRequest;
use Modules\Storefront\Http\Requests\UpdateBlockRequest;
use Modules\Storefront\Models\HomepageBlock;

class HomepageAdminController
{
    public function __construct(
        private readonly FileStorage $files,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    public function index(): Response
    {
        abort_unless(request()->user()->can('storefront.homepage.manage'), 403);

        $blocks = HomepageBlock::query()->orderBy('position')->get()
            ->map(fn (HomepageBlock $b) => [
                'id' => $b->id,
                'type' => $b->type->value,
                'payload' => $b->payload,
                'visible' => $b->visible,
                'logo_url' => null, // per-blok image URL viz níže
            ]);

        return inertia('Modules/Storefront/Homepage', [
            'blocks' => $blocks,
            'blockTypes' => array_map(fn (BlockType $t) => $t->value, BlockType::cases()),
        ]);
    }

    public function store(StoreBlockRequest $request): RedirectResponse
    {
        $type = $request->blockType();
        HomepageBlock::create([
            'position' => (int) HomepageBlock::query()->max('position') + 1,
            'type' => $type,
            'payload' => $type->defaultPayload(),
            'visible' => true,
        ]);

        return back()->with('success', 'Blok byl přidán.');
    }

    public function update(UpdateBlockRequest $request, HomepageBlock $block): RedirectResponse
    {
        $payload = $request->cleanPayload($block->type, $this->sanitizer);

        if ($request->hasFile('image')) {
            $ext = $request->file('image')->extension();
            $path = "homepage/{$block->id}.{$ext}";
            $this->files->putPublic($path, file_get_contents($request->file('image')->getRealPath()));
            $imageField = $block->type === BlockType::Banner ? 'image_path' : 'image_path';
            $payload[$imageField] = $path;
        }

        $block->update([
            'payload' => $payload,
            'visible' => $request->boolean('visible', $block->visible),
        ]);

        return back()->with('success', 'Blok byl uložen.');
    }

    public function move(MoveBlockRequest $request, HomepageBlock $block): RedirectResponse
    {
        $dir = $request->validated('direction');
        $neighbor = HomepageBlock::query()
            ->when($dir === 'up',
                fn ($q) => $q->where('position', '<', $block->position)->orderByDesc('position'),
                fn ($q) => $q->where('position', '>', $block->position)->orderBy('position'))
            ->first();

        if ($neighbor !== null) {
            [$block->position, $neighbor->position] = [$neighbor->position, $block->position];
            $block->save();
            $neighbor->save();
        }

        return back();
    }

    public function toggle(ToggleBlockRequest $request, HomepageBlock $block): RedirectResponse
    {
        $block->update(['visible' => $request->boolean('visible')]);

        return back();
    }

    public function destroy(HomepageBlock $block): RedirectResponse
    {
        abort_unless(request()->user()->can('storefront.homepage.manage'), 403);

        if (! empty($block->payload['image_path'])) {
            $this->files->delete($block->payload['image_path'], private: false);
        }
        $block->delete();

        return back()->with('success', 'Blok byl smazán.');
    }
}
```
> Route-model binding `{block}` → `HomepageBlock` je automaticky tenant-scoped přes `BelongsToTenant` global scope → cizí blok = 404 (žádný leak). Upload obrázků (image validace raster-only) přidej do `UpdateBlockRequest::rules()`: `'image' => ['sometimes','image','mimes:png,jpg,jpeg,webp','max:2048']`.

- [ ] **Step 4: Napiš admin testy**

```php
// tests/Feature/Storefront/HomepageAdminTest.php
it('adds a block', function () {
    [$tenant, $owner] = makeShopWithOwner();
    actingAsOwner($owner, $tenant)
        ->post(adminUrl($tenant, '/admin/m/storefront/homepage/blok'), ['type' => 'text'])
        ->assertRedirect();
    expect(blocksOf($tenant)->count())->toBeGreaterThan(0);
});

it('rejects a foreign block (404)', function () {
    [$a, $ownerA] = makeShopWithOwner();
    [$b] = makeShopWithOwner();
    $foreignBlock = blockOn($b);
    actingAsOwner($ownerA, $a)
        ->delete(adminUrl($a, "/admin/m/storefront/homepage/blok/{$foreignBlock->id}"))
        ->assertNotFound();
});

it('rejects javascript: cta url', function () {
    [$tenant, $owner] = makeShopWithOwner();
    $hero = heroBlockOn($tenant);
    actingAsOwner($owner, $tenant)
        ->patch(adminUrl($tenant, "/admin/m/storefront/homepage/blok/{$hero->id}"), [
            'payload' => ['title' => 'x', 'cta_label' => 'Klik', 'cta_url' => 'javascript:alert(1)'],
        ])
        ->assertSessionHasErrors('payload.cta_url');
});

it('reorders blocks with move up', function () { /* seed 2 bloky, move druhý up, ověř position swap */ });

it('sanitizes text block html on save', function () {
    [$tenant, $owner] = makeShopWithOwner();
    $text = textBlockOn($tenant);
    actingAsOwner($owner, $tenant)
        ->patch(adminUrl($tenant, "/admin/m/storefront/homepage/blok/{$text->id}"), [
            'payload' => ['html' => '<p>ok</p><script>alert(1)</script>'],
        ]);
    expect(freshPayload($text)['html'])->not->toContain('<script>');
});

it('blocks writes for a suspended tenant', function () { /* suspended tenant → POST blok → 503 */ });
```
> Helpery (`makeShopWithOwner`, `actingAsOwner`, `adminUrl`) postav podle existujícího `CategoryAdminTest.php` — sdílí stejný modulový admin setup (host + owner membership + permission).

- [ ] **Step 5: Spusť admin testy**

Run: `php artisan test --compact --filter=HomepageAdminTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint Modules/Storefront/Http/Controllers/HomepageAdminController.php Modules/Storefront/routes/admin.php
git add Modules/Storefront/Http/Controllers/HomepageAdminController.php Modules/Storefront/routes/admin.php Modules/Storefront/module.json tests/Feature/Storefront/HomepageAdminTest.php
git commit -m "feat: homepage block admin (crud, reorder, nav, permission)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Inertia editor `Homepage.vue`

**Files:**
- Create: `resources/js/Pages/Modules/Storefront/Homepage.vue`
- (referenční vzor: `resources/js/Pages/Tenant/Appearance.vue`, `resources/js/Pages/Modules/Categories/Index.vue`)

**Interfaces:**
- Consumes: props `blocks` (`{id,type,payload,visible}[]`), `blockTypes` (`string[]`); routy `admin.storefront.homepage.*`.

- [ ] **Step 1: Postav stránku** — seznam bloků, per blok: štítek typu, stav viditelnosti, tlačítka **nahoru / dolů / skrýt / smazat** (smazat = potvrzovací dialog), „Upravit" (drawer/form per typ). „Přidat blok" = select typu + submit `store`.

Klíčové Inertia patterny (dle `Appearance.vue`):
```vue
<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3'
const props = defineProps<{ blocks: Array<{id:number;type:string;payload:Record<string,any>;visible:boolean}>, blockTypes: string[] }>()

function move(id:number, direction:'up'|'down') {
  router.patch(`/admin/m/storefront/homepage/blok/${id}/presun`, { direction }, { preserveScroll: true })
}
function toggle(b) {
  router.patch(`/admin/m/storefront/homepage/blok/${b.id}/viditelnost`, { visible: !b.visible }, { preserveScroll: true })
}
function destroy(id:number) {
  if (!confirm('Opravdu smazat blok?')) return
  router.delete(`/admin/m/storefront/homepage/blok/${id}`, { preserveScroll: true })
}
</script>
```

Per-typ editační form (drawer): pole odpovídají payloadu (hero: title/subtitle/cta_label/cta_url/image upload; product_row: heading + mode radio + count / product_ids; category_grid: heading + multiselect kategorií; text: heading + textarea html; banner: image upload + url + alt). Submit `PATCH update` s `preserveScroll`. Obrázky přes `useForm` s FormData (`forceFormData: true`).

- [ ] **Step 2: Ověř build**

Run: `npm run build`
Expected: bez chyb; stránka se zkompiluje.

- [ ] **Step 3: Ruční smoke test** (nepovinné) — přihlas se do admina tenanta, otevři „Homepage", přidej/přesuň/smaž blok, zkontroluj storefront.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Modules/Storefront/Homepage.vue
git commit -m "feat: homepage block editor (inertia)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Přístupnost + celková regrese + dokumentace

**Files:**
- Modify: `CLAUDE.md` (sekce Rozhodnutí + „Stojí jádro…" souhrn)
- Create: `docs/as-is/2026-07-26-blokova-homepage.md`
- Modify: `docs/as-is/STATUS.md`

- [ ] **Step 1: a11y check** — spusť `a11y-checker` agenta na `resources/js/Pages/Modules/Storefront/Homepage.vue` a render partialy; oprav nálezy (nadpisová hierarchie, focus, alt, tlačítka řazení klávesnicí).

- [ ] **Step 2: Plná testová sada dotčené oblasti**

Run: `php artisan test --compact --filter=Storefront`
Expected: vše PASS.

- [ ] **Step 3: Napiš as-is + aktualizuj CLAUDE rozhodnutí**

`docs/as-is/2026-07-26-blokova-homepage.md`: mapa změn, plnění spec, testy, **Odchylky** (page builder nad rámec MVP katalogu; page cache invalidace odložená), technický dluh (live preview, drag&drop, obecné stránky). CLAUDE.md: přidej rozhodnutí (bloky = relační tabulka v modulu storefront; seed jedna pravda; URL guard; page cache invalidace odložená protože page cache není) + rozšiř „Vlna 2.3 uzavřena" větu.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md docs/as-is
git commit -m "docs: wave 2.3 blokova homepage as-is + decisions

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review (autor plánu)

**Spec coverage:**
- Datový model → Task 1 ✓
- 5 typů bloků → Task 1 (enum) + Task 4 (partialy) ✓
- Storefront render → Task 4 ✓
- Seed (noví + backfill) → Task 2 + Task 3 ✓
- Admin editor → Task 6 (backend) + Task 7 (UI) ✓
- Bezpečnost (sanitizace text, raster obrázky, URL guard, tenant izolace, write-freeze, strop) → Task 5 + Task 6 ✓ (strop 30 bloků: doplnit do `StoreBlockRequest` — validace `HomepageBlock::count() < 30` v `authorize`/`withValidator`)
- Přístupnost + testy → Task 8 ✓

**Otevřené k dořešení při implementaci:**
- Strop 30 bloků: přidej check do `StoreBlockRequest` (Task 5/6) — v plánu zmíněno, doplnit konkrétní kód.
- `TenantPermissions` auto-přiřazení `storefront.homepage.manage` ownerovi — ověřit chování při implementaci Task 6 Step 1.
- Storefront test helpery (`makeShopTenant`, host setup) — použít existující z `tests/Feature/Storefront/`; pokud chybí sdílený helper, vytvořit v Task 4.
- Banner partial scaffold v Task 4 Step 4 je záměrně zjednodušen v poznámce pod blokem — řiď se poznámkou (`<img>` + alt + volitelný odkaz), ne rozepsaným scaffoldem.

**Type consistency:** `BlockType` backed values (`hero,product_row,category_grid,text,banner`) konzistentní napříč enumem, controllerem, partialy (`blocks.{type}` kde type = `hero,product-row,category-grid,text,banner` — pozor: view názvy s pomlčkou, enum values s podtržítkem; `prepare()` v Task 4 mapuje explicitně, drž to). `DefaultHomepage::seed`, `BlockUrl::isSafe`, `UpdateBlockRequest::cleanPayload` konzistentní.
