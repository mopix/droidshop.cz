# Vlna 2.3 — Bloková homepage (page builder) — design

Datum: 2026-07-26 · Fáze 2 · Navazuje na: vlna 2.2 (šablona storefrontu + branding), modul `storefront` (Blade SSR), `ProductCatalog`, `Category`, `HtmlSanitizer`, `FileStorage`, `TenantProvisioner`.

## Cíl

Umožnit nájemci **poskládat homepage e-shopu z bloků** místo dnešní fixní šablony (hero + chipy kategorií + grid novinek napevno). Nájemce v adminu přidává, řadí, edituje, skrývá a maže bloky. Storefront homepage zůstává **Blade SSR bez JS** (pravidlo `.claude/rules/storefront-rendering.md`) — builder je Inertia SPA v adminu (`noindex`), veřejná homepage je server-rendered a cache-safe.

Mimo rozsah (post-MVP): live preview / WYSIWYG, drag&drop řazení, bloková stavba obecných stránek (jen homepage), bloky galerie / FAQ / newsletter.

## Role

- `TENANT_ADMIN` / `TENANT_STAFF` s právem — edituje bloky v adminu (write-freeze na `suspended`/`past_due` platí přes `CheckTenantStatus`).
- `CUSTOMER` / anonym — vidí vyrenderovanou homepage (veřejné, cache-safe, per-tenant).

## Rozsah (co je uvnitř)

1. **Datový model** `homepage_blocks` (relační, řádek per blok, řazení `position`).
2. **5 typů bloků** s per-typ payloadem, render partial a validací.
3. **Storefront render** — `HomeController` iteruje bloky, čistý Blade SSR.
4. **Seed výchozích bloků** — `TenantProvisioner` (noví) + backfill migrace (stávající tenanti).
5. **Admin editor** — Inertia stránka, seznam bloků + form/drawer per typ, řazení tlačítky.
6. **Bezpečnost** — sanitizace textu, raster-only obrázky, URL validace, tenant izolace, cache invalidace.

## Umístění v architektuře

Vše v modulu `storefront` (je to prezentace šablony a závisí na běžícím katalogu). Storefront je **core modul** (nejde vypnout), takže modulová admin routa přes `module:storefront` gate vždy projde — konzistentní se vzorem ostatních modulových admin rout.

- Migrace + model: `Modules/Storefront/…` (`homepage_blocks`, model `HomepageBlock` s `BelongsToTenant`).
- Render partials: `storefront::components.blocks.{type}` (Blade komponenty).
- Admin controller: modulová admin routa `admin.storefront.homepage.*`.
- Inertia stránka: `resources/js/Pages/Modules/Storefront/Homepage.vue` (rozhodnutí 2026-07-20 — modulové Inertia stránky žijí v core stromu, ne v modulu; view finder je jinak nenajde).

## Datový model

Tabulka `homepage_blocks`:

| Sloupec | Typ | Poznámka |
|---------|-----|----------|
| `id` | PK | |
| `tenant_id` | FK | `BelongsToTenant`, index |
| `position` | unsigned int | řazení, `orderBy` vzestupně |
| `type` | string (enum) | `hero` \| `product_row` \| `category_grid` \| `text` \| `banner` |
| `payload` | JSON | per-typ pole, validovaná při zápisu |
| `visible` | bool | default `true`; skryté se nerenderují ani neposílají do SEO |
| `created_at` / `updated_at` | timestamps | |

Model `HomepageBlock`:
- `BelongsToTenant` (global scope + `tenant_id` auto-fill).
- `casts`: `payload` → `array`, `visible` → `bool`.
- Scope `visible()` a default `orderBy('position')` na dotaz z homepage.
- Enum `BlockType` (`app/`-style enum v modulu) s metodou vracející povolený tvar payloadu / validační pravidla.

`payload` je JSON, protože tvar se liší per typ — relační sloupce by nutily nullable les. Validace tvaru běží **při zápisu** (FormRequest per typ), takže render čte důvěryhodná data.

## Typy bloků

Každý blok = payload + render komponenta `x-storefront::blocks.{type}`. Neznámý/nepodporovaný typ (modul vypnutý) se **vynechá**, nerozbije stránku.

### `hero`
- Payload: `title` (text), `subtitle` (text, nullable), `cta_label` (text, nullable), `cta_url` (URL, nullable), `image_path` (nullable, `FileStorage tenant_public`).
- Render: velký nadpis + podnadpis + CTA tlačítko (`bg-brand`) + volitelný obrázek na pozadí/vedle.
- Bezpečnost: `title`/`subtitle`/`cta_label` plain text (Blade `{{ }}` escape). `cta_url` validace (viz Bezpečnost). Obrázek raster-only.

### `product_row`
- Payload: `heading` (text, nullable), `mode` (`latest` \| `manual`), `count` (int 1–12, pro `latest`), `product_ids` (int[], pro `manual`).
- Render: `heading` + `x-storefront::product-grid`. `latest` → `ProductCatalog::latest($count)`; `manual` → produkty dle `product_ids` přes katalog, **v pořadí výběru**, zmizelé/skryté se vynechají.
- Vynechá se, když modul `products` neběží (`ShopModules::has('products')`).

### `category_grid`
- Payload: `heading` (text, nullable), `category_ids` (int[], prázdné = všechny top-level).
- Render: `heading` + mřížka karet kategorií (`Category::visible()`, filtr dle `category_ids` nebo `whereNull('parent_id')`).
- Vynechá se, když modul `categories` neběží.

### `text`
- Payload: `heading` (text, nullable), `html` (sanitizované HTML).
- Render: nadpis + `{!! $html !!}` (bezpečné, sanitizováno při zápisu).
- Bezpečnost: `html` prochází `App\Core\Html\HtmlSanitizer` **při zápisu** (rozhodnutí 2026-07-20 — čistit při zápisu, ne při renderu).

### `banner`
- Payload: `image_path` (`FileStorage tenant_public`, raster-only), `url` (URL, nullable), `alt` (text).
- Render: obrázek (odkazovaný, pokud `url`), `alt` povinné pro přístupnost.
- Bezpečnost: raster-only (žádné SVG = stored-XSS vzor, rozhodnutí 2026-07-25 favicon). `url` validace. Prázdný `alt` → `alt=""` (dekorativní) jen pokud nájemce vědomě, jinak varování v adminu.

## Storefront render

`HomeController::render`:
1. Načte `HomepageBlock::visible()->orderBy('position')->get()` pro aktuální tenant.
2. Pro každý blok ověří modul (`ShopModules`) a předá do view.
3. `home.blade.php` iteruje bloky a includuje `x-storefront::blocks.{type}` (nebo `@switch`).
4. SEO (title/meta/canonical/OG/JSON-LD `Organization`+`WebSite`) zůstává na úrovni homepage, beze změny. Bloky nemění `<head>`.

Čistý Blade SSR, žádný nový storefront JS. Ověření: `curl | grep` vidí obsah bloků v HTML; JS vypnutý = homepage plná.

## Seed výchozích bloků

Nový tenant i stávající tenanti musí mít neprázdnou homepage odpovídající dnešnímu vzhledu.

- **Noví:** `TenantProvisioner` po aktivaci modulů založí default sadu:
  1. `hero` — title = název e-shopu, subtitle = uvítací text, bez CTA/obrázku.
  2. `product_row` — mode `latest`, count 8, heading „Novinky".
  3. `category_grid` — prázdné `category_ids` (všechny top-level), heading „Kategorie".
- **Stávající:** backfill migrace projde tenanty bez bloků a založí stejnou sadu (idempotentně — jen tenanti s 0 bloky).
- Seed logika ve sdílené službě (`HomepageSeeder` / `DefaultHomepage`), volaná provisionerem i migrací — jedna pravda, ne dvě cesty (vzor `DemoShopSeeder` → `TenantProvisioner`).

## Admin editor

Modulová admin routa (skupina `['web','tenant.member','module:storefront']`), write-freeze přes `CheckTenantStatus`:

- `GET /admin/vzhled/homepage` → `admin.storefront.homepage.edit`
- `POST /admin/vzhled/homepage/blok` → přidat blok (typ + prázdný/def payload)
- `PATCH /admin/vzhled/homepage/blok/{block}` → editovat payload
- `PATCH /admin/vzhled/homepage/blok/{block}/presun` → posun nahoru/dolů (přepočet `position`)
- `PATCH /admin/vzhled/homepage/blok/{block}/viditelnost` → skrýt/zobrazit
- `DELETE /admin/vzhled/homepage/blok/{block}` → smazat (potvrzovací dialog — mazací akce)
- Upload obrázků přes `FileStorage tenant_public` (raster-only validace).

Inertia `Pages/Modules/Storefront/Homepage.vue`:
- Seznam bloků (typ, náhledový štítek, stav viditelnosti), tlačítka **nahoru / dolů / skrýt / smazat**.
- Řazení **tlačítky** (WCAG 2.1.1, rozhodnutí „drag&drop nikdy jako jediná cesta"); drag = volitelná nadstavba později.
- „Přidat blok" — výběr typu, pak form/drawer per typ.
- Editace payloadu ve formuláři/draweru specifickém pro typ (server validace → `form.errors`).
- Odkaz „Zobrazit e-shop" (náhled homepage na storefrontu).
- Nav: **samostatná položka „Homepage"** v adminu vedle „Vzhled" (discoverability, vzor domény/fakturace/vzhled).

Ownership každého bloku ověřen `BelongsToTenant` scope — route-model binding vrátí 404 na cizí blok (žádný leak).

## Bezpečnost

- **Auth/role:** modulová admin routa přes `tenant.member` + `module:storefront`; write-freeze verb-based (`CheckTenantStatus`).
- **XSS text:** `text` blok `html` sanitizován `HtmlSanitizer` při zápisu; ostatní textová pole plain (Blade escape).
- **XSS obrázky:** logo/hero/banner raster-only (`png,jpg,jpeg,webp`; žádné SVG — stored-XSS vzor 2026-07-25), přes `FileStorage tenant_public` + `image` validator.
- **URL validace:** `cta_url` / banner `url` — povolit jen relativní cestu začínající `/` **nebo** absolutní `http(s)://`; odmítnout `javascript:`, `data:`, `vbscript:` a bezschemé. Validace ve FormRequestu.
- **Tenant izolace:** `BelongsToTenant` global scope; test, že blok tenanta A není vidět/editovatelný z kontextu B.
- **CSRF:** admin POST/PATCH/DELETE přes web skupinu (VerifyCsrfToken platí).
- **Cache:** po jakékoli změně bloků invalidovat page cache homepage tenanta (existující tag mechanismus §15.6). Bloky jsou per-tenant, ne per-návštěvník → cache-safe, smí do cachovaného HTML.
- **DoS/limit:** rozumný strop počtu bloků na homepage (např. 30) v FormRequestu, aby se stránka nedala nafouknout.

## Přístupnost + SEO

- **WCAG 2.2 AA:** řazení klávesnicí (tlačítka), banner/hero obrázek `alt`, nadpisová hierarchie (`h1` hero / `h2` sekce), CTA jako odkaz/tlačítko s textem, focus stavy z tokenů.
- **SEO:** homepage title/meta/canonical/OG/JSON-LD beze změny; bloky mění jen `<main>` obsah. Skryté bloky se nerenderují. Obrázky s `alt`.
- **Výkon:** žádný nový storefront JS; obrázky rozumně velké (validace), lazy `loading` na ne-hero obrázcích; render bloků = pár DB dotazů (bloky + katalog eager).

## Testování

Feature/unit (Pest/PHPUnit):
- **Tenant izolace:** blok tenanta A neviditelný a needitovatelný z kontextu B (route-model binding 404).
- **Render bez JS:** `curl`/`get('/')` obsahuje obsah každého typu bloku v HTML; skrytý blok chybí.
- **Řazení:** posun nahoru/dolů přepočítá `position`, render respektuje pořadí.
- **Sanitizace:** `text` blok se `<script>` uloží očištěný; `cta_url`/`url` s `javascript:` odmítnut.
- **Obrázky:** SVG upload odmítnut; raster přijat.
- **Seed:** `TenantProvisioner` založí default sadu; backfill migrace jen pro tenanty s 0 bloky (idempotence).
- **Fallback:** tenant bez bloků (edge) → prázdný `<main>` bez chyby (ale seed to v praxi eliminuje).
- **Modul vypnutý:** `product_row` bez modulu `products` se vynechá, stránka nespadne.
- **Write-freeze:** suspended tenant nemůže POST/PATCH/DELETE bloky (503).
- **Zmizelý produkt/kategorie:** `manual` product_row se zmizelým ID ho vynechá, nespadne.

## Odchylky od specifikace

- Produktová spec neřeší page builder homepage explicitně (§4.1 storefront je katalogově orientovaný). Bloková homepage je produktové rozšíření nad rámec MVP katalogu — zapsat do sekce Odchylky v as-is.
- Homepage URL `/` beze změny (kernel `StorefrontHome` kontrakt drží).

## Technický dluh / follow-up

- Live preview + drag&drop řazení = pozdější UX nadstavba.
- Bloková stavba obecných stránek (Pages) = budoucí vlna (stejný engine, jiný kontext).
- Další typy bloků (galerie, FAQ akordeon, newsletter, loga značek) dle poptávky.
- Verzování/koncept homepage (draft vs publish) — zatím přímá editace živé homepage.
