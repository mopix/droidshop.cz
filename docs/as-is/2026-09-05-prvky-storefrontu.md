# Prvky storefrontu a šablona `katalog` — as-is

**Datum:** 2026-09-05
**Vlna:** 4.2
**Spec:** [`docs/superpowers/specs/2026-09-05-prvky-storefrontu-design.md`](../superpowers/specs/2026-09-05-prvky-storefrontu-design.md)
**Plán:** [`docs/superpowers/plans/2026-09-05-prvky-storefrontu.md`](../superpowers/plans/2026-09-05-prvky-storefrontu.md)

## Co je hotové

Vlna 4.1 dala platformě šablony, ale ukázalo se, že šablona sama rozdíl neudělá: referenční
e-shop stojí na **prvcích**, které platforma neměla. Tahle vlna je postavila — a teprve nad nimi
vznikla čtvrtá šablona.

- **Pět nových bloků titulky:** karusel, pás výhod, produkty se záložkami, mozaika kategorií,
  řada bannerů. Všechny renderuje server, všechny fungují bez JavaScriptu, všechny se editují
  v administraci.
- **Vlastnosti produktu a fasetové filtry** ve výpisu kategorie — číselník nájemce, filtrování
  přes URL, počty u hodnot, `noindex` na filtrované kombinaci.
- **Doplňky s příplatkem** — vlastní řádek košíku, objednávky i faktury, s vlastní sazbou DPH.
- **Drobnosti nákupní cesty:** částka úspory, počet na stránku, stránkování nahoře, lightbox,
  kotvy sekcí a sticky lišta.
- **Šablona `katalog`** postavená na tom všem.

## Mapa změn

### Bloky (etapa A)

| soubor | co dělá |
|---|---|
| `Modules/Storefront/Enums/BlockType.php` | pět nových typů + `itemBounds()` (mezní počty položek) |
| `Modules/Storefront/Support/UspIcons.php` | uzavřená sada ikon |
| `Modules/Storefront/Http/Requests/UpdateBlockRequest.php` | validace položkových bloků, indexované uploady |
| `Modules/Storefront/Http/Controllers/HomepageAdminController.php` | `resolveItemImages()` — cesty obrázků položek se odvozují na serveru |
| `Modules/Storefront/Http/Controllers/HomeController.php` | data bloků, aktivní záložka z `?zalozka=` |
| `Modules/Storefront/Resources/views/components/blocks/{slider,usp-strip,product-tabs,category-mosaic,banner-grid}.blade.php` | render |
| `resources/js/Components/Storefront/BlockItems.vue` | sdílený editor seznamu položek |
| `app/Core/PageCache/PageCacheKey.php`, `config/pagecache.php` | `zalozka` v klíči, normalizovaná |

### Vlastnosti a fasety (etapa B)

| soubor | co dělá |
|---|---|
| migrace `…_create_product_attributes` | `product_attributes`, `product_attribute_values`, pivot |
| `Modules/Products/Models/ProductAttribute.php`, `ProductAttributeValue.php` | |
| `Modules/Products/Services/AttributeWriter.php` | kódy, slugy, přiřazení produktu, odmítnutí smazání používané vlastnosti |
| `app/Core/Catalog/ProductQuery.php` | `attributes`, `PER_PAGE`, `normaliseAttributes()` |
| `Modules/Products/Services/EloquentProductCatalog.php` | filtrování (`whereExists` na vlastnost) a `facetCounts()` |
| `app/Core/Catalog/Contracts/ProductFacets.php`, `FacetGroup`, `NullProductFacets` | kontrakt pro výpis |
| `Modules/Products/Services/EloquentProductFacets.php` | implementace |
| `Modules/Storefront/Resources/views/components/facet-panel.blade.php` | panel jako GET formulář |
| `Modules/Products/Http/Controllers/AttributeAdminController.php` + `Attributes.vue` | číselník v administraci |

### Doplňky (etapa C)

| soubor | co dělá |
|---|---|
| migrace `…_create_product_addons`, `…_add_addons_to_cart_items`, `…_add_addons_to_order_items` | schéma |
| `Modules/Products/Models/ProductAddonGroup.php`, `ProductAddon.php` | |
| `app/Core/Catalog/Contracts/ProductAddons.php`, `AddonGroup`, `AddonOption`, `NullProductAddons` | kontrakt |
| `Modules/Products/Services/EloquentProductAddons.php` | implementace; `find()` ověřuje příslušnost k produktu |
| `Modules/Checkout/Services/EloquentCartRepository.php` | doplňkové řádky, `addon_hash` v identitě řádku, kaskáda množství a mazání |
| `Modules/Checkout/Services/CartPricer.php` | ocenění doplňkového řádku |
| `Modules/Checkout/Http/Controllers/CartController.php` | ověření příslušnosti a povinných skupin |
| `Modules/Orders/Services/OrderPlacer.php` | doplňkový řádek objednávky, `parent_item_id`, přeskočení skladu |
| `Modules/Products/Resources/views/components/addons.blade.php` | dlaždice na detailu |
| `Modules/Products/Http/Controllers/AddonAdminController.php` | správa na kartě produktu |

### Drobnosti a šablona (etapy D, E)

`resources/js/storefront.js` (lightbox, sticky lišta), `sort-form.blade.php` (počet na stránku),
tři detaily produktu (částka úspory), `themes/katalog/**`, `resources/css/themes/katalog.css`,
písma přesunuta pod `public/fonts/{archivo,source-sans-3}/`.

## Rozhodnutí, která stojí za připomenutí

**Doplněk je vlastní řádek, ne příplatek v ceně produktu.** Je to věc, kterou nájemce prodává,
takže musí být na faktuře se svým názvem a svou sazbou. Kdyby se přičetl do ceny produktu,
dostal by plátno i rám jednu sazbu — a to je chyba, která zajímá finanční úřad, ne designéra.

**Identita řádku košíku nese hash sady doplňků.** Stejný obraz s dubovým rámem a bez rámu jsou
dvě věci, které si zákazník koupil. Podle `addon_id` je rozlišit nejde — oba produktové řádky
tam mají nulu.

**Filtr: NEBO uvnitř vlastnosti, A ZÁROVEŇ mezi vlastnostmi.** Implementováno jedním
`whereExists` na vlastnost; smyčka `whereHas` s `orWhere` uvnitř čte stejně a vyrobí průnik tam,
kde má být sjednocení — zákazník, který klikne na dvě barvy, by viděl jen zboží, které je nějak
obojí.

**Neznámý kód nebo hodnota filtru se zahodí, nefiltruje se na nic.** Odkaz zvětrá, když nájemce
přejmenuje hodnotu, a zvětralý odkaz má zákazníka pustit na regál, ne na „nic neprodáváme".

**Každý nový parametr je normalizovaný v klíči page cache.** `vlastnost` se řadí a odduplikuje
toutéž funkcí, kterou čte katalog; `zalozka` a `na-stranku` padají na výchozí klíč, když nedávají
smysl. Bez toho by dvě pořadí jednoho filtru byly dva záznamy s identickým HTML a každý sken by
si vyrobil vlastní.

## Testy

| soubor | co hlídá | počet |
|---|---|---|
| `HomepageBlockPayloadTest` | payload, meze položek, ikony, cizí `image_path`, indexovaný upload | 13 |
| `HomepageBlocksRenderTest` (rozšíření) | všechny slidy v HTML, záložky jako odkazy, mozaika | 16 |
| `PageCacheKeyTest` (rozšíření) | `zalozka`, `vlastnost`, `na-stranku` v klíči | 30 |
| `ProductAttributeTest` | číselník, slugy, izolace, odmítnutí smazání | 8 |
| `ProductFacetTest` | sjednocení/průnik, neznámé hodnoty, počty, normalizace | 8 |
| `StorefrontFacetTest` | GET panel, `noindex`, canonical, shop bez modulu | 5 |
| `AttributeAdminTest` | CRUD, oprávnění, izolace, uložení z karty produktu | 5 |
| `CartAddonTest` | vlastní řádek, cizí doplněk, povinná skupina, kaskáda, absence ovládání | 11 |
| `OrderAddonTest` | řádek objednávky, součet, sklad, sazba, faktura | 6 |
| `SaleStorefrontTest` (rozšíření) | částka úspory ze stejné reference jako procento | 6 |
| `e2e/addons-no-js.spec.ts` | nákup doplňku bez JavaScriptu | 1 |
| `e2e/themes*.spec.ts` | nově i šablona `katalog` | 13 |

## Odchylky od plánu

1. **Plán neměl úkol na administraci doplňků.** Bez ní by nájemce neměl jak založit to, co celá
   etapa prodává — doplněna jako součást Tasku C4 (karta produktu, záložka Doplňky).

2. **`cart_items.addon_hash` v plánu nebyl.** Vyšlo najevo při psaní testu: unikátní index nemůže
   odlišit dva produktové řádky lišící se jen sadou doplňků, protože `addon_id` mají oba nulové.

3. **Doplňky se neodepisují ze skladu** (vědomě, v souladu se spec) a nenesou hmotnost ani
   rozměry — zásilka je produktová a doplněk, který by přidal svou, nafoukne údaj pro dopravce.

4. **Šablona `katalog` sdílí písmo s `retail`.** Proto se soubory přesunuly z `public/fonts/{téma}/`
   pod název rodiny: druhá šablona se stejným písmem nemá platit za přejmenování.

5. **Fasety zatím nemají whitelist indexovatelných kombinací** — filtrovaná stránka je vždy
   `noindex`. Zůstává mimo rozsah, jak spec říká.

## Nálezy z proklikání

- **Doplněk vypadal v košíku jako samostatný produkt.** `CartPricer` staví řádky podruhé kvůli
  rozpočtu slevy a při té stavbě zahodil vazbu na rodiče. Každý doplněk tak měl vlastní pole
  množství a vlastní „Odebrat" — tedy nabídku sundat obrazu rám, který pak dorazí nezarámovaný,
  aniž to kdo rozhodl. Opraveno, hlídá test na **nepřítomnost** obou formulářů.
- **Lokální databáze neměla `addon_hash`**, protože migrace se po úpravě nepřehrála. Produkce
  nedotčená; připomínka, že `migrate:rollback` po ruční editaci migrace je nutný krok, ne detail.

## Technický dluh a co hlídat

- **Fasety nad velkým katalogem:** `facetCounts()` dělá jeden dotaz na vlastnost. Při deseti
  vlastnostech je to deset dotazů na každý výpis. Zatím v pohodě, ale je to první místo, které
  začne bolet.
- **Doplňky a slevy:** procentní sleva na košík se rozpočítá i na doplňkové řádky. Zatím správně
  (je to zboží v košíku), ale sleva cílená na produkt se doplňku netýká — kdyby to nájemce čekal,
  je to překvapení.
- **Sticky lišta a lišta souhlasu** sedí obě dole. Bez JS se nezobrazí ani jedna, s JS se lišta
  souhlasu skryje po rozhodnutí — ale mezi tím se překrývají.
- **Editor bloků nemá náhled.** Nájemce skládá titulku naslepo a výsledek vidí až na e-shopu.

## Pre-deploy checklist

- [ ] `php artisan migrate` — `product_attributes` (3 tabulky), `product_addons` (2 tabulky),
      `cart_items.addon_id/parent_item_id/addon_hash` (+ přestavěný unikátní index),
      `order_items.parent_item_id/addon_id`
- [ ] `npm run build` — nový Vite vstup `themes/katalog.css`
- [ ] Ověřit nasazení statických souborů `public/fonts/archivo/` a `public/fonts/source-sans-3/`
      (přesunuty z `public/fonts/{editorial,retail}/`)
- [ ] Po nasazení vyzkoušet nákup s doplňkem a zkontrolovat, že faktura nese doplněk jako
      samostatný řádek se správnou sazbou
- [ ] Projít výpis s filtrem a ověřit, že filtrovaná stránka má `noindex` a canonical na
      nefiltrovanou kategorii
