# Vlna 2.7 — Akční ceny produktu + evidence nejnižší ceny za 30 dní — design

Datum: 2026-07-28 · Fáze 2 · Navazuje na: `products` (`Product`, `ProductVariant`, `EloquentProductCatalog` jako cenová autorita), `checkout` (`CartPricer`), `orders` (`OrderPlacer`, `OrderEditor`), `discounts` (vlna 2.6 jako druhá vrstva nad cenou), `storefront` (bloky homepage), `shipping` (`tax_rate_id` poplatků).

**Status:** approved

## Cíl

Nájemce zlevní produkt na určené období a storefront to ukáže tak, jak vyžaduje novela zákona o ochraně spotřebitele (§ 12a zákona č. 634/1992 Sb., směrnice EU Omnibus): u oznámené slevy musí být uvedena **nejnižší cena, za kterou se zboží prodávalo v posledních 30 dnech** před poskytnutím slevy.

Cenová autorita zůstává na serveru. `ProductCatalog::price()` nadále vrací **skutečně placenou** cenu — nově tedy akční, když akce běží — takže košík, objednávka, doklady i slevový engine z 2.6 dostanou správnou částku bez změny volajícího kódu.

Vlna zároveň zavírá nesený dluh z 2.6: **poplatek za dopravu a platbu bez `tax_rate_id` vypadne z DPH rekapitulace, ačkoli se účtuje** (AK 4 vlny 2.6, `CartPricer::vatBreakdown()` a `OrderPlacer::vatSummary()`).

Celý tok **musí fungovat s vypnutým JavaScriptem** (`.claude/rules/storefront-rendering.md`).

## Mimo rozsah (→ `docs/future/`)

- Hromadné nastavení akcí (vybrat N produktů, zlevnit o X %) a import cen z CSV
- Admin přehled běžících a naplánovaných akcí jako samostatná obrazovka
- Filtr „ve slevě" ve storefront katalogu (otevírá fasety a canonical/`noindex` politiku filtrů, kterou zatím nemáme)
- Omnibus u veřejně oznámených **automatických pravidel** z vlny 2.6 (např. „−10 % na kategorii") — hraniční případ, chce vlastní rozbor
- Akční ceny ve feedech Heureka/Zboží/Google (feedy zatím nejsou)
- Ceníky pro zákaznické skupiny a B2B cenové hladiny
- Množstevní slevy (cena od N kusů)

## Role

| Role | Co smí |
|------|--------|
| `TENANT_ADMIN` s `products.manage` | nastavit akční cenu a její období na produktu i variantě |
| `TENANT_STAFF` | nic navíc (právo `products.manage` mu lze udělit, až role vznikne) |
| `SUPERADMIN` | **nic navíc** — cena je obchodní rozhodnutí nájemce |
| `CUSTOMER` / anonym | vidí akční cenu, nominální cenu a povinný údaj o nejnižší 30denní ceně na storefrontu |

Modul `products` je **core**, takže akční ceny dostane každý tarif. Write-freeze na `suspended`/`past_due` platí přes `CheckTenantStatus` beze změny.

## Rozhodnutí z brainstormingu (závazná)

| Otázka | Rozhodnutí |
|--------|-----------|
| Rozsah akce | **Produkt i varianta**, časově ohraničená |
| Okno akce | **Jen na produktu** (jedna kampaň); varianta nese pouze částku |
| Dědičnost | Varianta bez vlastní `price` dědí i `sale_price` produktu; varianta s vlastní `price` musí mít i vlastní `sale_price` |
| Evidence 30 dní | **Časová řada efektivních cen** (`product_price_history`), ne denní snapshot |
| Plánované přechody | Zapisovač ukládá i **budoucí** intervaly, takže konec akce nepotřebuje cron |
| Zobrazení | Akční + přeškrtnutá nominální + řádek „Nejnižší cena za posledních 30 dní" |
| Výpočet procenta | **Z nejnižší 30denní ceny**, ne z nominální |
| `compare_at_price` | **Odstranit** — dnes mrtvý sloupec, vedle `sale_price` by to byla past |
| Rozsah UI | Pole u produktu a v mřížce variant + badge ve výpisu; žádné hromadné operace |
| DPH poplatků | `tax_rate_id` **povinné pro plátce**, backfill migrací na `TaxRates::default()` |

## Datový model

### `products` (alter)

| Sloupec | Typ | Poznámka |
|---------|-----|----------|
| `sale_price` | `unsignedBigInteger` nullable | Hrubá cena v haléřích, stejná měna i sazba DPH jako `price` |
| `sale_starts_at` | `timestamp` nullable | `null` = platí od okamžiku uložení |
| `sale_ends_at` | `timestamp` nullable | `null` = otevřený konec |
| `compare_at_price` | — | **DROP** |

### `product_variants` (alter)

| Sloupec | Typ | Poznámka |
|---------|-----|----------|
| `sale_price` | `unsignedBigInteger` nullable | Absolutní částka, nikdy procento z produktu |

### `product_price_history` (nová, modul `products`)

| Sloupec | Typ | Poznámka |
|---------|-----|----------|
| `id` | `id` | |
| `tenant_id` | FK `tenants` cascade | Tenant scope jako všude jinde |
| `product_id` | FK `products` cascade | |
| `variant_id` | FK `product_variants` cascade, nullable | `null` = cena produktu bez variant |
| `price` | `unsignedBigInteger` | **Efektivní** hrubá cena platná v intervalu |
| `starts_at` | `timestamp` | |
| `ends_at` | `timestamp` nullable | `null` = dosud platné |
| `created_at` | `timestamp` | |

Index `(tenant_id, product_id, variant_id, starts_at)`; index na `ends_at` kvůli dotazu na překryv.

**Invariant:** řádek, jehož `starts_at` už nastal, se **nikdy nemění**. Přepisují se výhradně dosud nezačaté (plánované) řádky. Historie je doklad pro dozorový orgán — přepsaná minulost je horší než chybějící.

## Chování

### Efektivní cena

```
akce běží  ⟺  sale_price != null
              ∧ (sale_starts_at == null ∨ sale_starts_at <= now)
              ∧ (sale_ends_at   == null ∨ sale_ends_at   >  now)
```

Produkt: `effectivePrice() = akce běží ? sale_price : price`.

Varianta:
- základ = `variant.price ?? product.price`
- akční částka = `variant.sale_price ?? (variant.price === null ? product.sale_price : null)`
- okno se vždy čte z produktu

Varianta s vlastní základní cenou tedy **nedědí** produktovou akční částku. Absolutní akční cena zděděná na jiný cenový základ by tiše prodávala pod nákladem.

### Cenová autorita

`ProductCatalog::price()`, `CatalogProduct::catalogPrice()`, `catalogNetPrice()`, `catalogVat()`, `catalogPriceFrom()` a `CatalogVariant::catalogVariantPrice()` vracejí **efektivní** cenu. Žádný existující volající se nemění; košík, `OrderPlacer` i doklady tím dostanou akční částku a slevový engine z 2.6 počítá kupón z akční ceny, ne z nominální.

Rozšíření kontraktů v `app/Core/Catalog/Contracts/`:

- `CatalogProduct::catalogRegularPrice(): Money`
- `CatalogProduct::catalogIsOnSale(): bool`
- `CatalogProduct::catalogLowestPriceIn30Days(): ?Money`
- `CatalogVariant::catalogVariantRegularPrice(): Money`
- `CatalogVariant::catalogVariantIsOnSale(): bool`

### Řazení a výpis

`EloquentProductCatalog::paginate()` řadí `SORT_PRICE_ASC`/`SORT_PRICE_DESC` dnes podle sloupce `price`. Musí přejít na `CASE WHEN`ovou efektivní cenu (funguje v MySQL i SQLite), jinak zlevněný produkt sedí ve výpisu na místě podle staré ceny. Stejný výraz použije `catalogPriceFrom()`.

### Zápis historie

`PriceHistoryRecorder` (služba modulu `products`) volaná z `ProductWriter` a `VariantWriter` — jediné zapisovací cesty. Při každé změně `price`, `sale_price` nebo okna:

1. uzavře otevřený řádek k `now` (nebo ho zahodí, pokud ještě nezačal),
2. smaže dosud nezačaté plánované řádky téhož produktu/varianty,
3. zapíše aktuální efektivní cenu od `now`,
4. zapíše **plánované** intervaly odvozené z okna akce: akční řádek `[sale_starts_at, sale_ends_at)` a návratový řádek na nominální cenu od `sale_ends_at` dál.

Tím konec akce nepotřebuje cron ani job: interval už v tabulce leží.

### Nejnižší cena za 30 dní

`LowestPriceCalculator`: `MIN(price)` přes řádky s překryvem intervalu `[now − 30 dní, now]` pro daný produkt (a variantu, když je zvolená). Když historie nesahá 30 dní zpět, počítá se z dostupného období.

Hraniční případ: produkt nasazený rovnou v akci nemá starší historii, takže minimum vyjde rovno akční ceně. Pak se **badge s procentem nezobrazí** (nulová sleva vůči referenci), ale řádek s nejnižší cenou zůstane. Oznámení slevy bez uvedené reference je horší než oznámení bez procenta.

### Storefront

Detail produktu, výpis kategorie, bloky homepage a našeptávač: akční cena jako hlavní, nominální přeškrtnutá vedle ní, badge `−N %`. Na detailu produktu navíc vždy řádek „Nejnižší cena za posledních 30 dní: X Kč".

`N` se počítá jako `round((lowest30d − sale) / lowest30d × 100)`, tedy **z nejnižší 30denní ceny**.

Varianty: embedded matice v `variant-picker.blade.php` dostane server-formátované řetězce pro akční, nominální a nejnižší 30denní cenu; vanilla JS ostrůvek jen prohazuje hotové řetězce. Žádná cenová aritmetika v JS (spec §16.3).

### Admin

- Detail produktu: `sale_price`, `sale_starts_at`, `sale_ends_at`; pole `compare_at_price` mizí z formuláře i z Inertia propů.
- Mřížka variant: sloupec `sale_price`.
- Validace: `sale_price` musí být `< price` (u varianty `< effectivePrice` základu) a `sale_ends_at > sale_starts_at`.

### DPH poplatků (uzavření dluhu 2.6)

- `StoreShippingMethodRequest` a `StorePaymentMethodRequest`: `tax_rate_id` je `required`, když `tenants.vat_payer` je pravda; neplátce ho dál nemusí vyplňovat (jeho rekapitulace je stejně prázdná).
- Migrace doplní existujícím metodám plátců `TaxRates::default()`.
- `CartPricer::vatBreakdown()` a `OrderPlacer::vatSummary()` zůstávají beze změny — po backfillu už nemají co zahazovat.

## Akceptační kritéria

1. Nájemce nastaví na produktu akční cenu s obdobím; mimo období se prodává nominální cena, uvnitř akční — bez jakéhokoli ručního zásahu na hranici období.
2. Objednávka vytvořená během akce naúčtuje akční cenu a snímek řádku (`order_items.unit_price`) ji drží i po skončení akce.
3. Kupón z vlny 2.6 aplikovaný na zlevněný produkt počítá slevu z **akční** ceny.
4. Detail produktu ve slevě obsahuje v surovém HTML (bez JS) akční cenu, přeškrtnutou nominální cenu a řádek s nejnižší cenou za posledních 30 dní.
5. Nejnižší 30denní cena odpovídá skutečnému minimu efektivní ceny v okně, včetně akce, která začala před 30 dny a stále běží.
6. Varianta bez vlastní ceny dědí produktovou akci; varianta s vlastní cenou a bez vlastní akční ceny se prodává za svou nominální cenu.
7. Řazení katalogu podle ceny respektuje akční ceny.
8. Historický řádek, jehož interval už začal, nezmění žádná pozdější editace ceny.
9. Poplatek za dopravu i platbu vstupuje u plátce DPH do rekapitulace; součet rozpisu se rovná celkové částce objednávky.
10. Nájemce, který není plátcem DPH, uloží dopravu i platbu bez sazby a nic se nerozbije.

## Testy

- **Unit:** efektivní cena (před oknem / v okně / po okně / otevřený konec), dědičnost varianty, `LowestPriceCalculator` včetně intervalu přesahujícího hranici 30 dní a prázdné historie.
- **Feature:** objednávka za akční cenu, kupón nad akční cenou, řazení podle ceny, admin validace `sale_price < price`.
- **Storefront (SSR):** surové HTML detailu i výpisu nese obě ceny a povinný řádek; badge chybí u produktu bez starší historie.
- **Regrese DPH:** rozpis sedí se součtem u objednávky s poplatkem za dopravu i platbu; neplátce projde bez sazeb.
- **Tenant izolace:** historie ceny tenanta A není čitelná z tenanta B.

## Technické poznámky

- Migrace: alter `products` (+3 sloupce, −`compare_at_price`), alter `product_variants` (+1), create `product_price_history`, backfill `tax_rate_id` u metod plátců.
- Backfill historie: existující produkty dostanou při migraci jeden otevřený řádek s aktuální cenou od `now` — starší historie neexistuje a vymýšlet ji by znamenalo falšovat doklad.
- Dotčené soubory: `app/Core/Catalog/Contracts/{CatalogProduct,CatalogVariant,ProductCatalog}.php`, `Modules/Products/{Models,Services,Http}`, `Modules/Products/Resources/views/storefront/`, `resources/js/Pages/Modules/Products/Show.vue`, `resources/js/storefront.js`, `Modules/Shipping/Http/Requests/`.
- Konfigurace: okno 30 dní jako konstanta v `LowestPriceCalculator` (zákon ho neváže na nastavení nájemce).

## Reference

- Produktová spec: `docs/specs/2026-07-17-eshop-platforma-specifikace.md` §579 (novely spotřebitelského práva), §16.1 (katalog), §16.3 (checkout bez JS)
- Předchozí vlna: `docs/superpowers/specs/2026-07-28-vlna-26-slevovy-engine-design.md`
- Odložené kroky: `docs/future/slevy-dalsi-kroky.md`
- Plán: `docs/superpowers/plans/2026-07-28-vlna-27-akcni-ceny.md`
- As-is (po dokončení): `docs/as-is/2026-07-28-akcni-ceny.md`
