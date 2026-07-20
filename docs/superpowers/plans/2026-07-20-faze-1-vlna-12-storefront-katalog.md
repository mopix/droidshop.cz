# Storefront katalogu (Blade SSR + SEO) — implementační plán

> **Pro agenta:** Použij superpowers:executing-plans nebo subagent-driven-development. Kroky s `- [ ]`.

**Cíl:** Zveřejnit katalog tenanta jako serverem renderovaný e-shop se splněnými SEO výstupy a funkčními 301 redirecty.

**Architektura:** Nový modul `storefront` drží layout, homepage, hledání, sitemap, robots a chybové stránky; moduly `categories` a `products` dostávají vlastní `routes/storefront.php` a dědí layout přes `storefront::layouts.shop`. Redirecty se servírují až při `NotFoundHttpException`, takže úspěšné cesty nenesou žádný DB dotaz navíc.

**Tech stack:** Dle `docs/PROJECT-PROFILE.md` — Laravel 13, Blade SSR, Tailwind, Alpine ostrůvky, PHPUnit.

**Spec:** [`docs/superpowers/specs/2026-07-20-faze-1-vlna-12-storefront-katalog.md`](../specs/2026-07-20-faze-1-vlna-12-storefront-katalog.md)

---

## Blok A — Modul `storefront` a layout

- [ ] A1. Vytvořit `Modules/Storefront/module.json`: `name: storefront`, `core: true`, `level: base`, `requires: {}`, bez admin nav (v této vlně nemá admin obrazovky). Test: `ModuleRegistry` modul vidí a jde zapnout tenantovi.
- [ ] A2. `Modules/Storefront/Providers/ModuleProvider.php` — registrace view namespace `storefront::`, Blade komponent, view composeru pro layout (název e-shopu, navigace kořenových kategorií, patička).
- [ ] A3. `Modules/Storefront/Resources/views/layouts/shop.blade.php` — hlavička s `@stack('head')` pro per-stránkové meta a JSON-LD, skip link, `<main id="obsah">`, patička. Bez per-návštěvníkového obsahu (příprava na page cache).
- [ ] A4. Blade komponenty v `Modules/Storefront/Resources/views/components/`: `seo-meta` (title, description, canonical, OG/Twitter, robots direktiva), `json-ld`, `breadcrumbs`, `product-card`, `pagination` (s `rel=prev/next`).
- [ ] A5. Test: layout se vyrenderuje, obsahuje skip link a právě jeden `<h1>` slot.

## Blok B — Homepage a kolize s `/`

- [ ] B1. `Modules/Storefront/routes/storefront.php` — `GET /` → `HomeController@show`.
- [ ] B2. `routes/web.php` — Inertia `Welcome` a `/dashboard` omezit na platformní host (podmínka přes `ResolveHost::isPlatformHost` nebo doménové omezení routy). Test: `/` na hostu tenanta vrací storefront, na platformním hostu Welcome.
- [ ] B3. `HomeController` — nejnovější produkty, kořenové kategorie, název a kontakty z `SettingsService`. JSON-LD `Organization` + `WebSite`.
- [ ] B4. Test: homepage tenanta A neobsahuje produkt tenanta B.

## Blok C — Výpis kategorie

- [ ] C1. `Modules/Categories/routes/storefront.php` — `GET /kategorie/{category:slug}`.
- [ ] C2. `CategoryStorefrontController@show` — `scopeVisible`, produkty podstromu přes materializovanou `path`, stránkování 24, řazení (`nejnovejsi|cena-asc|cena-desc|nazev`) a filtr `skladem` z query parametrů. Eager loading obrázků a sazby DPH.
- [ ] C3. View `categories::storefront.show` — popis nad/pod, podkategorie, grid, stránkování; prázdná kategorie = 200 s hláškou.
- [ ] C4. SEO: canonical na konkrétní stránku, `rel=prev/next`, JSON-LD `ItemList` + `BreadcrumbList`, `noindex` u kombinací filtrů mimo whitelist.
- [ ] C5. Testy: viditelnost, izolace tenantů, řazení bez JS, počet dotazů (proti N+1), skrytá kategorie 404.

## Blok D — Detail produktu

- [ ] D1. `Modules/Products/routes/storefront.php` — `GET /produkt/{slug}`.
- [ ] D2. `ProductStorefrontController@show` — `scopePublished`, načtení `withTrashed` kvůli rozlišení 410 od 404, breadcrumbs z primární kategorie, cena s DPH i bez přes `TaxRate`.
- [ ] D3. View `products::storefront.show` — galerie, popis (už sanitizovaný, renderovat `{!! !!}`), dostupnost, neutrální stav místo košíku (modul `checkout` neexistuje).
- [ ] D4. SEO: `Product` + `Offer` JSON-LD (`availability`, `price`, `priceCurrency`, `sku`, `gtin13` když je EAN), OG image ze `seo_image_path` nebo hlavního obrázku.
- [ ] D5. Testy: `draft`/`hidden` = 404, izolace tenantů, cena je v surovém HTML, JSON-LD má platný `Offer`.

## Blok E — Redirecty, 410 a chybové stránky

- [ ] E1. `app/Exceptions/…` nebo `bootstrap/app.php` `withExceptions` — na `NotFoundHttpException` v kontextu tenanta zkusit `Redirect::where('from_path', …)` a vrátit `redirect($to, $status)`. Test: redirect tenanta A neplatí na hostu B, řetěz se skáče jedním krokem.
- [ ] E2. Soft-deleted produkt → `abort(410)` s view `storefront::errors.410` a odkazem na primární kategorii.
- [ ] E3. `Modules/Storefront/Resources/views/errors/{404,410,503}.blade.php` + napojení na error view resolution jen pro tenantské hosty (platformní admin si drží výchozí).
- [ ] E4. Testy: 301 po přejmenování slugu kategorie i produktu, 410 u smazaného produktu, 404 v šabloně tenanta.

## Blok F — Vyhledávání

- [ ] F1. Migrace: `products.search_text` (text, index) + naplnění existujících záznamů.
- [ ] F2. Normalizace při zápisu (observer nebo `saving` hook): lowercase, odstranění diakritiky, spojení názvu, SKU a krátkého popisu. Unit test na normalizaci („Bunda" ↔ „bunda").
- [ ] F3. `GET /hledani?q=` v modulu `storefront` — `LIKE` nad `search_text`, stránkování, řazení podle relevance (shoda na začátku názvu první).
- [ ] F4. Dotaz < 2 znaky = stránka s výzvou; 0 výsledků = `noindex, follow` a nabídka kategorií.
- [ ] F5. Testy: diakritika, izolace tenantů, `noindex` u prázdného výsledku.

## Blok G — `sitemap.xml` a `robots.txt`

- [ ] G1. `GET /sitemap.xml` — produkty `active`, kategorie `visible`, publikované stránky; `lastmod` z `updated_at`; absolutní URL na doméně tenanta. Cache per tenant (bez tagů, klíč nese tenant), invalidace časem.
- [ ] G2. Kontrola limitu 50 000 URL — přes limit zalogovat varování (sitemap index až s prvním velkým tenantem).
- [ ] G3. `GET /robots.txt` — `Disallow` na `/admin`, `/kosik`, `/pokladna`, `/hledani`; odkaz na sitemap. Tenant, jehož storefront neběží, dostane `Disallow: /`.
- [ ] G4. Testy: sitemap neobsahuje skryté a cizí entity, robots reaguje na stav tenanta.

## Blok H — Assety, přístupnost, výkon

- [ ] H1. Vite: nový vstup `resources/js/storefront.js` + `resources/css/storefront.css`, oddělený od admin bundlu. Alpine pro galerii a mobilní menu.
- [ ] H2. Tailwind content cesty rozšířit o `Modules/**/Resources/views/**/*.blade.php`.
- [ ] H3. Kontrola bundle < 100 kB gzip (`npm run build`, velikost zapsat do as-is).
- [ ] H4. Delegovat `a11y-checker` na storefront views; opravit nálezy blokující WCAG 2.2 AA.
- [ ] H5. Kontrolní seznam z `.claude/rules/storefront-rendering.md` (`curl -k`, JS vypnutý, canonical, JSON-LD) projít a výsledek zapsat.

## Blok I — Dokumentace a uzavření

- [ ] I1. `docs/as-is/2026-07-20-storefront-katalog.md` — mapa změn, plnění spec, odchylky (fulltext, `/stranka/{slug}`, page cache), technický dluh.
- [ ] I2. `docs/as-is/STATUS.md` — řádek storefrontu na hotovo, vyškrtnout „redirects nikdo neservíruje".
- [ ] I3. CLAUDE.md sekce Rozhodnutí — šablona jako modul, redirecty v handleru 404, hledání přes normalizovaný sloupec.
- [ ] I4. `VERSION` + `CHANGELOG.md` podle skillu `versioning` (0.9.0).

## Strategie testů

- Feature testy per routa na hostu tenanta (existující helper z vlny 1.1).
- Izolace: každá veřejná routa má test „tenant A na hostu B = 404".
- Modulová brána: vypnutý `products` = 404 na `/produkt/*`, žádný redirect na login.
- SEO: assertace nad surovým HTML (title, canonical, JSON-LD dekódovaný jako pole).
- N+1: `assertQueryCount`-styl kontrola na výpisu kategorie.

## Rizika a mitigace

Viz spec, sekce Rizika. Navíc: pořadí registrace routů modulů vůči `routes/web.php` je nutné ověřit testem hned v bloku B — pokud by se `/` z web.php registrovalo dřív, homepage se nikdy nezobrazí.
