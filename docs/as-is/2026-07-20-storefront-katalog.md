# As-is: Storefront katalogu (fáze 1, vlna 1.2)

Datum: **2026-07-20** · Verze: **0.9.0** · Větev: `feat/storefront-catalog`

Spec: [`docs/superpowers/specs/2026-07-20-faze-1-vlna-12-storefront-katalog.md`](../superpowers/specs/2026-07-20-faze-1-vlna-12-storefront-katalog.md)
Plán: [`docs/superpowers/plans/2026-07-20-faze-1-vlna-12-storefront-katalog.md`](../superpowers/plans/2026-07-20-faze-1-vlna-12-storefront-katalog.md)

## Co teď existuje

Katalog je veřejně dostupný. Na doméně tenanta odpovídá homepage, výpis kategorie, detail produktu, vyhledávání, `sitemap.xml` a `robots.txt`; přejmenované slugy vracejí 301 a stažené produkty 410. Vše serverem renderované Blade, bez frameworku na klientovi.

### Nový modul `storefront`

`core: true`, bez `requires`. Drží layout, homepage, hledání, sitemap, robots a chybové stránky.

| Soubor | Role |
|--------|------|
| `Modules/Storefront/module.json` | manifest; core modul, žádná admin navigace |
| `Providers/ModuleProvider.php` | binduje `StorefrontHome`, registruje chybové views, composer layoutu |
| `Http/Controllers/HomeController.php` | homepage; implementuje kernel kontrakt |
| `Http/Controllers/SearchController.php` | `/hledani` |
| `Http/Controllers/SitemapController.php` | `/sitemap.xml`, cache 1 h per tenant |
| `Http/Controllers/RobotsController.php` | `/robots.txt` |
| `Support/Seo.php` | hlava stránky (title, description, canonical, OG, robots, prev/next) |
| `Support/ShopModules.php` | „běží tenantovi modul X?" — místo manifestové závislosti |
| `Resources/views/layouts/shop.blade.php` | layout; skip link, hlavička, navigace, patička |
| `Resources/views/components/*` | `seo-meta`, `json-ld`, `breadcrumbs`, `product-card`, `product-grid`, `sort-form` |
| `Resources/views/errors/404.blade.php` | 404 v šabloně e-shopu, fallback na prostý HTML bez tenanta |

### Jádro

| Soubor | Změna |
|--------|-------|
| `app/Core/Storefront/Contracts/StorefrontHome.php` | nový kontrakt pro `/` |
| `app/Http/Controllers/StorefrontEntryController.php` | `/` — platformní marketing vs. e-shop dle hostu |
| `app/Core/Routing/RedirectResponder.php` | servírování `redirects` z handleru 404 |
| `app/Core/Catalog/ProductQuery.php` | vstupy výpisu (kategorie, řazení, skladem, hledání) |
| `app/Core/Catalog/Contracts/ProductCatalog.php` | + `latest()`, `paginate()` |
| `app/Core/Catalog/Contracts/CatalogProduct.php` | + krátký popis, obrázek, alt, URL |
| `bootstrap/app.php` | `withExceptions` → `RedirectResponder` |
| `routes/web.php` | `/` míří na `StorefrontEntryController` |

### Moduly katalogu

- `Modules/Categories/routes/storefront.php`, `Http/Controllers/CategoryStorefrontController.php`, `Resources/views/storefront/show.blade.php`
- `Modules/Products/routes/storefront.php`, `Http/Controllers/ProductStorefrontController.php`, `Resources/views/storefront/show.blade.php`
- `Modules/Products/Support/SearchText.php` + migrace `products.search_text` + `products:reindex-search`
- `Modules/Products/Services/EloquentProductCatalog.php` — `latest`, `paginate`, hledání nad normalizovaným sloupcem

### Assety

- `resources/js/storefront.js` (galerie, autosubmit filtrů), `resources/css/storefront.css`
- Vite: samostatné vstupy; Tailwind content rozšířen o `Modules/**/Resources/views`
- **Velikost: JS 250 B gzip, CSS 9,8 kB gzip** (limit dle pravidla 100 kB)

## Plnění specifikace

| Požadavek | Stav |
|-----------|------|
| Blade SSR pro homepage, kategorii, produkt, hledání | splněno |
| `<title>`, description, absolutní canonical, OG + Twitter | splněno |
| JSON-LD `Product`+`Offer`, `BreadcrumbList`, `ItemList`, `Organization`+`WebSite` | splněno |
| `rel=prev/next`, canonical na stránku | splněno (výpis kategorie) |
| `noindex` na hledání a filtrované kombinace | splněno |
| `sitemap.xml`, `robots.txt` per tenant | splněno |
| 301 z historických slugů | splněno |
| 410 u stažených produktů | splněno |
| Řazení a filtr bez JS | splněno (GET formulář) |
| Vyhledávání s češtinou | splněno normalizovaným sloupcem, ne fulltextem |
| Page cache §15.6 | **mimo vlnu** (samostatná vlna, čeká na Redis) |
| Košík, pokladna | **mimo vlnu** (modul `checkout`) |

## Odchylky od specifikace

1. **Vyhledávání není InnoDB fulltext (§16.1), ale normalizovaný sloupec `search_text` + `LIKE`.** Fulltext neumí české skloňování ani diakritiku a nejede na SQLite, kterou používají testy. Normalizace při zápisu je stejně povinná podle §4.1; fulltext index nad sloupcem jde doplnit bez změny API. Cena: `LIKE '%term%'` nepoužije index, u desítek tisíc produktů bude potřeba přepsat.
2. **Homepage neběží přes modulovou routu, ale přes kernel kontrakt `StorefrontHome`.** Core web routy se matchují dřív než modulové, takže modulová routa pro `/` by se nikdy netrefila. Kernel drží routu a ptá se implementace na její modulový klíč, takže kill switch i per-tenant aktivace platí dál.
3. **Modul `storefront` nedeklaruje `requires` na `categories` a `products`.** Je to core modul a nic pod core modulem nejde vypnout — deklarovaná závislost by z katalogu udělala nevypnutelný modul. Šablona se místo toho ptá za běhu (`ShopModules`) a vykreslí, co běží.
4. **Redirecty se neservírují middlewarem, ale z handleru `NotFoundHttpException`.** Úspěšná cesta tak nenese DB dotaz navíc.
5. **Chybové views se registrují přes `view.paths`, ne přes namespace hint.** Laravel si namespace `errors` při renderu chyby přestaví z `view.paths` (`Handler::registerErrorViewPaths`), takže hint by se zahodil právě ve chvíli, kdy má platit.
6. **Statické stránky zůstávají na `/stranka/{slug}`**, ne na `/{page-slug}` podle pravidla storefrontu — catch-all v kořeni by spolkl ostatní routy. Nevyřešeno, přesouvá se na vlnu, která zavede pořadí routů (kandidát: modul `theme` nebo explicitní registrace catch-all jako poslední).

## Testy

`tests/Feature/Storefront/` — 39 testů, celá sada **482 passed**.

| Soubor | Co hlídá |
|--------|----------|
| `StorefrontCatalogTest.php` | homepage/kategorie/produkt, izolace tenantů, draft a hidden, SEO výstupy, JSON-LD, řazení bez JS, `noindex` u filtru, modulová brána |
| `StorefrontRedirectTest.php` | 301 u produktu i kategorie, kolaps řetězu, query string, izolace, 410, 404 v šabloně e-shopu, POST se neredirectuje |
| `StorefrontSearchTest.php` | normalizace diakritiky, hledání dle SKU, izolace, krátký dotaz, `noindex`, reindex command |
| `SitemapAndRobotsTest.php` | obsah sitemap, izolace, validní XML, robots, nefunkční e-shop = `Disallow: /` |

`tests/TestCase.php` nově volá `withoutVite()` globálně — každá 404 teď renderuje šablonu e-shopu, takže by jinak celá sada závisela na `npm run build`.

## Technický dluh

- **Page cache §15.6 chybí.** Šablony jsou na ni připravené (žádný per-návštěvníkový obsah), ale TTFB zatím není chráněné.
- **`LIKE '%term%'` nepoužívá index** — viz odchylka 1.
- **Sitemap nemá index soubor.** Nad 50 000 URL se ořízne a zaloguje varování.
- **Stránkování kategorie nemá horní strop** — `?page=99999` vrátí prázdný výpis s 200. Kandidát na 404.
- **Chybí 503 stránka v šabloně e-shopu** — suspendovaný tenant dostává `tenancy.unavailable` z jádra.
- **Detail produktu nemá tlačítko do košíku** — čeká na modul `checkout`.
- **Ostrůvky jsou vanilla JS, ne Alpine.** Alpine se přidá, až bude potřeba stav (mini-košík, varianty).

## Pre-deploy checklist

- [ ] `npm run build` (storefront bundle je v manifestu)
- [ ] `php artisan products:reindex-search` po nasazení migrace
- [ ] Ověřit `curl -k https://<shop>/produkt/<slug> | grep` — cena v HTML
- [ ] Lighthouse SEO + Performance na vzorovém e-shopu
- [ ] Rich Results Test na detailu produktu a výpisu kategorie
