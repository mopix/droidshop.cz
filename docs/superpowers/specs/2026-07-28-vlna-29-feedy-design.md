# Vlna 2.9 — XML feedy pro Heureku a Zboží.cz — design

Datum: 2026-07-28 · Fáze 2 · Navazuje na: `products` (`ProductCatalog`, varianty z 2.4, akční ceny z 2.7), `categories` (strom), `shipping` (`ShippingOptions` pro blok dopravy), `storefront` (`SitemapController` jako XML precedent, `ShopModules` jako runtime gate).

**Status:** approved

## Cíl

Nájemce zapne v adminu feed a porovnávač si z jeho domény stáhne katalog: `/feed/heureka.xml` a `/feed/zbozi.xml`. Feed nese to, co zákazník v e-shopu skutečně zaplatí — včetně akční ceny z vlny 2.7 — a rozepisuje varianty tak, aby porovnávač našel konkrétní velikost.

Bez feedu český e-shop prakticky neprodává; Heureka a Zboží.cz jsou hlavní nákupní kanál.

## Mimo rozsah (→ `docs/future/`)

- **Google Merchant** (RSS 2.0 s `g:` namespace, vlastní číselník `google_product_category`, jiná pravidla dostupnosti) — samostatná vlna
- Glami, Favi a další oborové porovnávače
- **Import číselníků** Heureky a Zboží s našeptávačem v adminu (Heureka jich má přes 4 000, byl by to další sync command s guardem proti prázdnému feedu)
- Vyřazení jednotlivého produktu z feedu (per-product opt-out)
- Heureka „Ověřeno zákazníky" — konverzní pixel a dotazníkové API
- Invalidace cache feedu při editaci katalogu (čeká na page cache)
- Statistiky prokliků a měření konverze z feedu

## Role

| Role | Co smí |
|------|--------|
| `TENANT_ADMIN` s `feeds.manage` | zapnout a vypnout feed, nastavit dodací lhůtu, mapovat kategorie |
| `TENANT_STAFF` | nic navíc (právo lze udělit, až role vznikne) |
| `SUPERADMIN` | **nic navíc** — feed je obchodní rozhodnutí nájemce |
| anonym / porovnávač | číst zapnutý feed na doméně nájemce (veřejná URL, `noindex`) |

## Rozhodnutí z brainstormingu (závazná)

| Otázka | Rozhodnutí |
|--------|-----------|
| Rozsah | **Heureka + Zboží.cz**; Google Merchant až později |
| Modul | nový modul **`feeds`**, `level: base` (feed je v ČR podmínka prodeje, ne nadstandard) |
| Závislosti | žádné `requires`; runtime gate přes `ShopModules` (precedent `checkout`) |
| Kategorie porovnávače | **textové pole per kategorie a per feed**; prázdné degraduje na vlastní strom |
| Varianty | **každá varianta vlastní `SHOPITEM`** se sdíleným `ITEMGROUP_ID`, osy v `PARAM` |
| Vyprodané | **zůstává ve feedu** s `DELIVERY_DATE` mimo skladem |
| Doručení | **veřejná URL + cache**, zapnutí per feed, vypnutý vrací 404 |
| Cena | výhradně `catalogPrice()` — tedy včetně akční ceny z 2.7 |
| Doprava | z `ShippingOptions`; bez modulu `shipping` se blok vynechá |

## Datový model

### `product_feeds` (modul `feeds`)

| Sloupec | Typ | Poznámka |
|---|---|---|
| `id` | `id` | |
| `tenant_id` | FK `tenants` cascade | |
| `type` | string(16) | `heureka` \| `zbozi` |
| `enabled` | boolean, default false | vypnutý feed = 404 |
| `settings` | json nullable | výchozí `delivery_date` (dny), volitelný `shop_name` |
| `timestamps` | | |

Unique `(tenant_id, type)` — jeden řádek na feed a nájemce.

### `feed_category_mappings` (modul `feeds`)

| Sloupec | Typ | Poznámka |
|---|---|---|
| `id` | `id` | |
| `tenant_id` | FK `tenants` cascade | |
| `category_id` | FK `categories` cascade | |
| `type` | string(16) | `heureka` \| `zbozi` |
| `category_text` | string(500) | cesta z číselníku porovnávače |

Unique `(tenant_id, category_id, type)`.

Mapování je **vlastní tabulka, ne sloupce na `categories`**: modul `feeds` jde vypnout a nesmí po sobě nechat sloupce v cizím modulu.

## Chování

### Skladba položky

Produkt bez variant = jeden `SHOPITEM`. Produkt s variantami = jeden `SHOPITEM` na **aktivní** variantu, všechny se sdíleným `ITEMGROUP_ID` (id produktu) a osami v `PARAM`.

Pole položky:

| Pole | Zdroj |
|---|---|
| `ITEM_ID` | id produktu, u varianty `{produkt}-{varianta}` |
| `PRODUCTNAME` | název produktu (+ label varianty) |
| `DESCRIPTION` | `strip_tags` popisu, ořez na 2 000 znaků |
| `URL` | absolutní URL produktu na doméně nájemce |
| `IMGURL` | hlavní obrázek, `IMGURL_ALTERNATIVE` ostatní |
| `PRICE_VAT` | `catalogPrice()` / `catalogVariantPrice()` — **včetně akční ceny** |
| `MANUFACTURER` | výrobce, když je |
| `CATEGORYTEXT` | mapování, jinak vlastní strom (`Elektronika \| Klávesnice`) |
| `EAN`, `PRODUCTNO` | EAN a SKU |
| `DELIVERY_DATE` | `0` skladem, jinak výchozí lhůta z nastavení feedu |
| `ITEMGROUP_ID` | id produktu, jen u variant |
| `PARAM` | osy varianty (`Velikost` → `M`) |
| `DELIVERY` | dopravní metody z `ShippingOptions` |

Do feedu jdou jen produkty, které vidí zákazník (`Product::published()`) — stejný filtr jako sitemap. Nezveřejněný produkt poslaný crawlerovi je únik rozpracovaného katalogu.

### Doručení

`GET /feed/heureka.xml` a `/feed/zbozi.xml` na doméně nájemce; XML se staví na request a cachuje 1 hodinu (vzor `SitemapController`). Vypnutý feed vrací **404**, ne prázdné XML. Hlavička `X-Robots-Tag: noindex`.

### Admin

`/admin/m/feeds` (permission `feeds.manage`): přepínač per feed s adresou ke zkopírování, výchozí dodací lhůta, tabulka kategorií se dvěma textovými poli.

## Akceptační kritéria

1. Zapnutý feed vrací platné XML s položkami katalogu; vypnutý vrací 404.
2. Produkt se třemi variantami je ve feedu jako tři položky se shodným `ITEMGROUP_ID` a osami v `PARAM`.
3. `PRICE_VAT` odpovídá ceně, kterou zákazník zaplatí v košíku, včetně běžící akce.
4. Vyprodaná položka ve feedu zůstává a nese `DELIVERY_DATE` mimo skladem.
5. Koncept ani skrytý produkt ve feedu nejsou.
6. Vyplněné mapování kategorie se použije v `CATEGORYTEXT`; prázdné degraduje na vlastní strom.
7. Feed na doméně nájemce A neobsahuje žádnou položku nájemce B.
8. Název s `&` a `<` feed nerozbije (správně escapované).
9. Blok `DELIVERY` nese skutečné dopravní metody; bez modulu `shipping` chybí celý blok, ne nulová cena.
10. Nájemce zapne feed a namapuje kategorie z adminu bez zásahu do kódu.

## Testy

- **Feature:** struktura obou feedů, povinná pole položky, varianty a `ITEMGROUP_ID`, akční cena, vyprodané s dodací lhůtou, koncept/skrytý mimo feed, vypnutý feed 404, tenant izolace, mapování kategorie i jeho fallback, escaping `&`/`<`, blok `DELIVERY` s modulem i bez něj.
- **Admin:** zapnutí a vypnutí feedu, uložení mapování, cizí kategorie se neuloží, právo `feeds.manage`.
- **Modul:** manifest, aktivace, kill switch (vypnutý modul → feed 404).

## Technické poznámky

- Migrace: `product_feeds`, `feed_category_mappings`.
- Nové soubory: `Modules/Feeds/` (manifest, routy, controllery, `Support/FeedItem*`, Blade šablony), `resources/js/Pages/Modules/Feeds/Index.vue`.
- Cache klíč `feed:{tenant}:{type}`, TTL 3600 (stejně jako sitemap).
- Popis se do XML dává jako text po `strip_tags`; Blade `{{ }}` escaping stačí, CDATA se nepoužívá.

## Reference

- Produktová spec: `docs/specs/2026-07-17-eshop-platforma-specifikace.md` §6.2, §16.1
- XML precedent: `Modules/Storefront/Http/Controllers/SitemapController.php`
- Předchozí vlna: `docs/superpowers/specs/2026-07-28-vlna-28-csv-import-design.md`
- Plán: `docs/superpowers/plans/2026-07-28-vlna-29-feedy.md`
- As-is (po dokončení): `docs/as-is/2026-07-28-feedy.md`
