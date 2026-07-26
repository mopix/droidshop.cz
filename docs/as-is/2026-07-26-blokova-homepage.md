# As-is: Bloková homepage (vlna 2.3)

Datum: 2026-07-26 · Fáze 2 · Branch: `feature/wave-2.3-blokova-homepage`
Spec: [`docs/superpowers/specs/2026-07-26-vlna-23-blokova-homepage-design.md`](../superpowers/specs/2026-07-26-vlna-23-blokova-homepage-design.md)
Plán: [`docs/superpowers/plans/2026-07-26-vlna-23-blokova-homepage.md`](../superpowers/plans/2026-07-26-vlna-23-blokova-homepage.md)

## Co vlna přinesla

Nájemce si homepage e-shopu poskládá z **bloků** místo dřívější fixní šablony (hero + chipy kategorií + grid novinek napevno). Storefront homepage zůstává **Blade SSR bez JS**; editor bloků je Inertia SPA v adminu.

5 typů bloků: `hero`, `product_row` (novinky / ruční výběr), `category_grid`, `text` (sanitizované HTML), `banner` (obrázek + odkaz).

## Mapa změn (kód)

### Modul `storefront`
- `Database/Migrations/…_create_homepage_blocks_table.php` — tabulka `homepage_blocks` (`tenant_id` FK cascade, `position`, `type`, `payload` JSON, `visible`, composite index `[tenant_id, position]`).
- `Database/Migrations/…_seed_default_homepage_blocks.php` — backfill výchozí sady pro stávající tenanty (idempotentní).
- `Models/HomepageBlock.php` — `BelongsToTenant`, casts (`type`→`BlockType`, `payload`→array, `visible`→bool), scope `visible()`.
- `Enums/BlockType.php` — 5 cases + `defaultPayload()`.
- `Support/DefaultHomepage.php` — jediný seed recept (hero + product_row latest 8 + category_grid). Volá provisioner i backfill migrace.
- `Support/BlockUrl.php` — `isSafe()`: povolí interní `/…` a `http(s)://`, odmítne `javascript:`/`data:`/`vbscript:`/protocol-relative `//`/backslash trik.
- `Http/Controllers/HomeController.php` — render: načte visible bloky `orderBy position`, `prepare()` mapuje typ na view + data (produkty z `ProductCatalog`, kategorie z `Category`), vynechá bloky vypnutých modulů.
- `Http/Controllers/HomepageAdminController.php` — CRUD (index/store/update/move/toggle/destroy). `update` ukládá **sanitizovaný** payload (`cleanPayload`), `image_path` **server-authoritative** (odvozen z uploadu, klient ho nediktuje). `store` odmítne druhý `hero`.
- `Http/Requests/{Store,Update,Move,Toggle}BlockRequest.php` — validace payloadu per typ, URL guard, raster-only image (`png,jpg,jpeg,webp`), strop 30 bloků, banner `alt` required s obrázkem, `authorize` na `storefront.homepage.manage`.
- `routes/admin.php` — modulové admin routy `admin.storefront.homepage.*`.
- `module.json` — permission `storefront.homepage.manage` + nav „Homepage".
- `Resources/views/home.blade.php` — iterace bloků přes `@includeFirst`.
- `Resources/views/components/blocks/{hero,product-row,category-grid,text,banner}.blade.php` — render partialy.

### Core
- `app/Core/Tenancy/TenantProvisioner.php` — po aktivaci modulů seedne default homepage (resolve modulu **stringem** `app('Modules\\Storefront\\…')`, aby core neměl compile-time modulový import).
- `app/Core/Html/HtmlSanitizer.php` — `<img>` bez `alt` dostane `alt=""` (a11y).

### Admin (core strom)
- `resources/js/Pages/Modules/Storefront/Homepage.vue` — editor: seznam bloků, řazení tlačítky (nahoru/dolů), skrýt/zobrazit, editace per typ, upload obrázků (`POST` + `_method=patch` spoofing pro multipart), mazání přes `ConfirmDialog`.

## Plnění spec

| Oblast spec | Stav |
|-------------|------|
| Datový model relační tabulka | ✅ |
| 5 typů bloků | ✅ |
| Storefront render Blade SSR bez JS | ✅ |
| Seed (noví + backfill) | ✅ |
| Admin editor (řazení tlačítky, WCAG) | ✅ |
| Bezpečnost (sanitizace, raster-only, URL guard, tenant izolace, write-freeze, strop 30) | ✅ |
| Přístupnost WCAG 2.2 AA | ✅ (audit + opravy, viz níže) |

## Testy

- `tests/Feature/Storefront/HomepageSeedTest.php` — tenant scope, seed při provisioningu, idempotence, backfill jen prázdných.
- `tests/Feature/Storefront/HomepageBlocksRenderTest.php` — render per typ, skrytý blok chybí, modul vypnutý vynechá, banner (s/bez odkazu), hero obrázek + alt.
- `tests/Feature/Storefront/HomepageAdminTest.php` — CRUD, tenant izolace 404, `javascript:` odmítnut, text sanitizace uložení, řazení, write-freeze 503, image_path forge (upload i no-file), SVG reject, druhý hero odmítnut.
- `tests/Unit/Storefront/BlockUrlTest.php` — bezpečné/nebezpečné URL včetně protocol-relative/backslash.
- `tests/Unit/Html/HtmlSanitizerTest.php` — `<img>` bez alt dostane `alt=""`.

## Odchylky od specifikace

- **Page builder homepage není v produktové spec** (§4.1 storefront je katalogově orientovaný). Bloková homepage = produktové rozšíření nad rámec MVP katalogu.
- **Homepage URL `/` beze změny** (kernel `StorefrontHome` kontrakt).
- **„Celá nabídka →" odkaz z fixní homepage odstraněn** blokovým přepisem — cesta do katalogu je nyní přes `category_grid` blok. (Produktové rozhodnutí — lze doplnit jako budoucí typ bloku / CTA.)
- **`category_ids` / `product_ids` v editoru = comma-text pole** (MVP), ne plný multiselect s našeptávačem — vyžadovalo by předání seznamu kategorií/produktů jako props. Follow-up.

## Přístupnost (WCAG 2.2 AA)

Audit `a11y-checker` proveden na editoru + partialech. Opraveno: jediný `<h1>` (odmítnutí druhého hero), hero obrázek se renderuje + má `alt`, `id` sekcí z `block->id` (ne md5 nadpisu → žádná kolize), move/toggle emitují success flash (SR status), banner `alt` required s obrázkem, sanitizer doplní `alt=""`, error summary `role="alert"` + focus na první chybu, format hinty u uploadu, min target size řadících tlačítek.

**Odloženo (follow-up):** `Modal.vue` `prefers-reduced-motion` (sdílená komponenta, mimo scope vlny); varování „otevírá se v novém okně" u `target="_blank"` odkazů v text bloku (zvážit zákaz `target` v sanitizeru); prevence přeskočení úrovně nadpisu v text bloku.

## Technický dluh / follow-up

- Live preview + drag&drop řazení (UX nadstavba).
- Bloková stavba obecných stránek (Pages) — stejný engine, jiný kontext.
- Další typy bloků (galerie, FAQ akordeon, newsletter, loga značek).
- Verzování/koncept homepage (draft vs publish) — zatím přímá editace živé homepage.
- `product_row` manual mód: `findById` per id (N+1) — batchnout u velkých výběrů.
- Editor: image preview thumbnail (index vrací raw `image_path`, chybí resolved URL prop) + plný multiselect kategorií/produktů.
- Osiřelý veřejný soubor při změně přípony nahraného obrázku (deterministický název `homepage/{id}.{ext}`).
- Page cache invalidace: **page cache zatím není implementovaná**; až přijde, doplnit invalidaci homepage po editaci bloků.

## Pre-deploy

- Backfill migrace `…_seed_default_homepage_blocks` proběhne při deployi (seedne homepage stávajícím tenantům bez bloků).
