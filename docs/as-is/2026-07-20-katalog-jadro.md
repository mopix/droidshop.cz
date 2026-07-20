# As-is: jádro katalogu (fáze 1 / vlna 1.1)

**Datum:** 2026-07-20 · **Verze:** 0.8.0 · **Větev:** `feat/catalog-core`
**Plán:** [`docs/superpowers/plans/2026-07-20-faze-1-vlna-11-katalog-jadro.md`](../superpowers/plans/2026-07-20-faze-1-vlna-11-katalog-jadro.md)
**Spec:** §6.2, §6.3, §16.1, §16.2, §15.3

Nájemce se přihlásí do vlastního adminu, založí strom kategorií a produkty s cenami, DPH, skladem, obrázky a SEO poli. Ostatní moduly čtou katalog přes kontrakt `ProductCatalog`. Storefront zatím nic nepublikuje — přijde ve vlně 1.2.

Testy: **443 zelených** (1159 asercí) na MySQL 8 + Redis. Bylo 319 před vlnou.

---

## Co vzniklo

### Jádro

| Oblast | Soubory | Poznámka |
|---|---|---|
| Členství a autorizace | `app/Http/Middleware/EnsureTenantMember.php`, `app/Core/Auth/TenantPermissions.php`, `app/Models/TenantMembership.php` | Gate registruje `app/Providers/ModuleServiceProvider.php` |
| Sazby DPH | `app/Core/Tax/TaxRates.php`, `app/Models/TaxRate.php`, migrace `create_tax_rates_table` | Globální tabulka, promile jako integer |
| Přesměrování | `app/Core/Routing/RedirectRegistry.php`, `app/Models/Redirect.php` | Middleware pro servírování zatím není |
| Sanitizace HTML | `app/Core/Html/HtmlSanitizer.php` | Whitelist nad `DOMDocument`, bez nové závislosti |
| Kontrakt katalogu | `app/Core/Catalog/Contracts/{ProductCatalog,CatalogProduct}.php`, `Exceptions/InsufficientStock.php` | Rozhraní v jádře, implementace v modulu |
| Admin shell | `resources/js/Layouts/AdminLayout.vue`, `app/Http/Middleware/HandleInertiaRequests.php` | Navigace z `NavigationBuilder` |
| Sdílené UI | `resources/js/Components/Ui/{DataTable,Pagination,ConfirmDialog,FilterBar}.vue` | Přesun z `Components/Platform` |

### Modul `categories`

`Modules/Categories/` — `Services/CategoryTree.php` (jediná zápisová cesta), model, migrace, Form Requesty, `CategoryAdminController`, routy.
UI: `resources/js/Pages/Modules/Categories/{Index.vue,Partials/CategoryBranch.vue,types.ts}`.

### Modul `products`

`Modules/Products/` — `Services/{ProductWriter,EloquentProductCatalog,ProductImageService,ProductsLimitCounter}.php`, modely `Product`/`Manufacturer`/`ProductImage`, `Rules/Ean.php`, Form Requesty, dva controllery, `Providers/ModuleProvider.php`.
UI: `resources/js/Pages/Modules/Products/{Index.vue,Show.vue}`.

---

## Bezpečnostní nálezy vlny

1. **Admin routy modulů byly volné.** `ModuleRouteRegistrar::mountAdmin()` montoval pod `['web', 'module:{key}']` — bez `auth`. Kdokoli bez přihlášení mohl číst a zapisovat cizí e-shop; týkalo se i nasazeného modulu `Pages`. Opraveno v prvním commitu vlny.

   Laravelí alias `auth` se schválně nepoužil: sedí v middleware priority listu a byl by přeřazen před modulový gate, čímž by z jeho 404 udělal redirect na login a prozradil, které moduly e-shop provozuje.

2. **Oprávnění z `module.json` nikdo nevynucoval.** Manifesty práva deklarovaly, ale žádná Gate je nečetla.

3. **Nákupní cena.** Ořez v `validated()`, ne v UI; hodnota navíc neopouští server bez práva `products.costs`.

4. **Nahrávané obrázky.** Laravelí pravidlo `image` rozhoduje podle přípony. Přidána kontrola `getimagesize()`, jinak by HTML soubor přejmenovaný na `.jpg` šel servírovat z originu e-shopu.

5. **XSS v popisu produktu.** Sanitizace při zápisu, ne při renderu.

---

## Odchylky od specifikace a plánu

1. **Převody DPH sedí na `TaxRate`, ne na `Money`** (plán počítal s `Money::netFromGross()`). `Money` je nejprimitivnější hodnotový typ jádra a nesmí znát daň — závislost míří jen jedním směrem.

2. **Inertia stránky modulů leží v `resources/js/Pages/Modules/<Modul>/`, ne uvnitř modulu.** Inertia view finder skládá cestu jako `{page_path}/{component}.vue`, takže krátký název `Modules/Products/Show` nejde namapovat na soubor uvnitř `Modules/Products/Resources/js/Pages/` bez vlastního finderu. Blade views, routy, controllery a migrace v modulu zůstávají. Cena: smazání modulu nechá osiřelý adresář v core stromu.

3. **Strom kategorií vlastní implementací**, ne `staudenmeir/laravel-adjacency-list` (rozhodnutí před plánem). Max hloubka 4 dělá rekurzi levnou a balíčkové CTE dotazy by obcházely `TenantScope`.

4. **Soft-deleted produkt si nechává soubory obrázků.** Plán říkal „smazání produktu smaže soubory". Produkt jde obnovit a staré objednávky ho mohou zobrazovat. Soubory jdou až při force delete; purge tenanta je pokrytý přes `FileStorage::deleteTenantPrefix()`. Důsledek: smazané produkty dál počítají do `storage_mb` (do limitu `products` už ne).

5. **Sanitizace HTML vlastní, ne `ezyang/htmlpurifier`.** Plán připouštěl novou závislost po dotazu; `DOMDocument` stačil.

6. **Řazení kategorií je tlačítky ↑/↓, ne drag&drop** (spec §16.2 zmiňuje drag&drop). Tažení nejde ovládat klávesnicí a admin to musí umět (WCAG 2.1.1). Drag&drop lze doplnit jako nadstavbu, ne jako jedinou cestu.

7. **`ProductImage` nemá řezy.** Servíruje se originál. Image cuts podle §6.2 přijdou s vlnou 1.2.

---

## Mimo rozsah (vědomě odloženo)

- Varianty produktu (`product_options`, `product_option_values`, `product_variants`)
- Import a export CSV, šablony mapování
- Generování řezů obrázků a WebP/AVIF
- Hromadné operace (aktivace, přecenění, přesun kategorie)
- MySQL fulltext a našeptávač
- Storefront rendering, JSON-LD, sitemap
- Middleware, který `redirects` skutečně servíruje
- Upozornění na pokles skladu pod `stock_alert_qty` (čeká na `MailService`)

---

## Technický dluh

| Věc | Dopad | Kde |
|---|---|---|
| `redirects` se zapisují, ale nic je nečte | Přejmenovaný slug zatím 404 | vlna storefrontu |
| Osiřelé Vue soubory po smazání modulu | Kosmetika, ne funkce | `resources/js/Pages/Modules/` |
| Soft-deleted produkty počítají do `storage_mb` | Nájemce platí za smazané | `ProductImageService` |
| `TenantRole::Staff` je datově hotová, ale nikde se nepřiděluje | Práva stojí, UI pro personál ne | fáze 2 |
| `EloquentProductCatalog::price()` nemá řetěz `PriceModifier` | Slevy a skupiny zákazníků nejsou | až s moduly, které je zavedou |
| Vyhledávání v adminu je `LIKE '%…%'` | Nad 10 000 produkty pomalé | fulltext ve vlně 1.2 |

---

## Testy

| Soubor | Co hlídá |
|---|---|
| `tests/Feature/Modules/ModuleAdminRouteTest.php` | Anonym a cizí uživatel se do adminu modulu nedostanou |
| `tests/Feature/Core/TenantPermissionsTest.php` | Práva z manifestů, owner vs. staff, vypnutý modul |
| `tests/Feature/Core/TaxRateTest.php` | Sazby, zaokrouhlení na haléře, součet net + DPH = brutto |
| `tests/Feature/Core/RedirectRegistryTest.php` | 301, kolaps řetězců, izolace mezi e-shopy |
| `tests/Unit/Html/HtmlSanitizerTest.php` | `<script>`, `onclick`, `javascript:`, `data:`, diakritika |
| `tests/Feature/Modules/CategoryTreeTest.php` | Cykly, hloubka, přesun podstromu, 301 |
| `tests/Feature/Modules/CategoryAdminTest.php` | Oprávnění, mazání s cílem, izolace |
| `tests/Feature/Modules/ProductCatalogTest.php` | Kontrakt, atomický sklad, limity, sanitizace |
| `tests/Feature/Modules/ProductImageTest.php` | Cesty, MIME, obsah souboru, hlavní obrázek |
| `tests/Feature/Modules/ProductAdminTest.php` | Validace, nákupní cena, limit tarifu, N+1 |

Chybí: E2E (Playwright je pořád blokovaný certifikátem, viz `STATUS.md`) a souběžnostní test `decrementStock` proti reálnému paralelnímu provozu — testuje se jednoprocesově.

---

## Vedlejší nález

`tests/Feature/Platform/TenantIndexTest.php` byl reálně flaky. Hledání pokrývá `billing_name`, který faktory plnila faker `company()` v cs_CZ — příjmení jako „Kolář" obsahuje hledané „Kola". Padalo přibližně jednou z deseti běhů. Připnuto.

---

## Pre-deploy checklist

- [ ] `php artisan migrate` — pět nových tabulek (`tax_rates`, `redirects`, `categories`, `manufacturers`, `products`, `product_images`, `product_category`)
- [ ] `php artisan modules:sync` — zaregistruje `categories` a `products`
- [ ] Moduly přiřadit tarifům (`plan_modules`), jinak je nájemce nezapne
- [ ] `npm run build`
- [ ] Ověřit zápis do `storage/app/public` a symlink pro veřejný disk
