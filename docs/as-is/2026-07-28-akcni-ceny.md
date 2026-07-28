# As-is: Akční ceny produktu + nejnižší cena za 30 dní (vlna 2.7)

Datum: 2026-07-28 · Verze: **0.24.x** (patch bumpy zvedá pre-commit hook; minor uzavře `/finish-wave`) · Větev: `feature/vlna-27-akcni-ceny` · **1514 testů (5326 assertions)** zelených

Spec: [`docs/superpowers/specs/2026-07-28-vlna-27-akcni-ceny-design.md`](../superpowers/specs/2026-07-28-vlna-27-akcni-ceny-design.md)
Plán: [`docs/superpowers/plans/2026-07-28-vlna-27-akcni-ceny.md`](../superpowers/plans/2026-07-28-vlna-27-akcni-ceny.md)

## Co vlna přinesla

Nájemce zlevní produkt i variantu na určené období. Storefront ukáže akční cenu, přeškrtnutou nominální a povinný údaj o **nejnižší ceně za posledních 30 dní** podle § 12a zákona č. 634/1992 Sb. (směrnice Omnibus). Cenová autorita zůstává na serveru: `ProductCatalog::price()` nově vrací akční cenu, takže košík, objednávka, doklady i slevový engine z vlny 2.6 účtují správně bez jediné změny volajícího kódu.

Vlna zároveň zavírá dluh nesený z 2.6: poplatek za dopravu a platbu bez `tax_rate_id` se účtoval, ale vypadával z DPH rekapitulace.

## Mapa změn

### Jádro — `app/Core/Catalog/Contracts/`

| Soubor | Změna |
|--------|-------|
| `CatalogProduct.php` | +`catalogRegularPrice()`, +`catalogIsOnSale()`, +`catalogLowestPriceIn30Days()`; `catalogPrice()` nově dokumentuje, že vrací **skutečně placenou** cenu |
| `CatalogVariant.php` | +`catalogVariantRegularPrice()`, +`catalogVariantIsOnSale()` |

### Modul `Modules/Products/`

| Soubor | Role |
|--------|------|
| `Database/Migrations/…_add_sale_price_to_products.php` | `products.sale_price|sale_starts_at|sale_ends_at`, `product_variants.sale_price`, **drop `compare_at_price`** |
| `Database/Migrations/…_create_product_price_history.php` | tabulka `product_price_history` (časová řada efektivních cen) |
| `Database/Migrations/…_backfill_price_history.php` | existující produkty i varianty dostanou jeden otevřený interval od okamžiku migrace |
| `Models/ProductPriceHistory.php` | model řady; `UPDATED_AT = null`, `price` přes `MoneyCast` |
| `Models/Product.php` | `saleWindowIsOpen()`, `saleIsRunning()`, `effectivePrice()`, statický `effectivePriceExpression()` (SQL pro řazení); `netPrice()`/`vat()`/`catalogPriceFrom()` počítají z efektivní ceny |
| `Models/ProductVariant.php` | `regularPrice()`, `saleIsRunning()`, přepsané `effectivePrice()`, privátní `saleAmount()` (dědičné pravidlo) |
| `Services/PriceHistoryRecorder.php` | jediný zapisovač řady, včetně **plánovaných budoucích** intervalů |
| `Services/LowestPriceCalculator.php` | `WINDOW_DAYS = 30`, `forProduct()`, `forVariant()` |
| `Services/EloquentProductCatalog.php` | `price()` vrací efektivní cenu; řazení `SORT_PRICE_*` přes `CASE WHEN` |
| `Services/ProductWriter.php`, `Services/VariantWriter.php` | volají zapisovač po každém zápisu ceny; `updateVariant()` whitelistuje `sale_price` |
| `Http/Requests/StoreProductRequest.php` | `sale_price` (`lt:price`), `sale_starts_at`, `sale_ends_at` (`after:sale_starts_at`) |
| `Http/Requests/UpdateProductVariantRequest.php` | `sale_price` nullable |
| `Http/Controllers/ProductAdminController.php` | props akce na produktu i variantě; `compare_at_price` pryč |
| `Resources/views/storefront/show.blade.php` | akční + přeškrtnutá cena, badge, povinný řádek; JSON-LD `Offer` kvotuje efektivní cenu |
| `Resources/views/storefront/partials/variant-picker.blade.php` | matice nese `regular_price` a `on_sale` |

### Ostatní

- `Modules/Storefront/Resources/views/components/product-card.blade.php` — akční cena a přeškrtnutá nominální ve výpisu
- `resources/js/storefront.js` — ostrůvek přepíná i přeškrtnutou cenu; **žádná aritmetika v JS**, jen server-formátované řetězce
- `resources/js/Pages/Modules/Products/Show.vue` — pole akční ceny a okna, sloupec akční ceny v mřížce variant
- `Modules/Shipping/Http/Requests/Store{Shipping,Payment}MethodRequest.php` — `tax_rate_id` povinné pro plátce DPH
- `Modules/Shipping/Database/Migrations/…_backfill_fee_tax_rates.php` — existujícím metodám plátců doplní výchozí sazbu

## Jak to funguje

### Efektivní cena

```
akce běží  ⟺  sale_price != null ∧ okno produktu je otevřené
```

Okno (`sale_starts_at`/`sale_ends_at`) sedí **jen na produktu** — jedna kampaň, částky per varianta. Varianta bez vlastní `price` dědí i produktovou `sale_price`; varianta s vlastní `price` musí mít vlastní `sale_price`, jinak jede na nominální ceně (absolutní částka zděděná na jiný cenový základ by tiše prodávala pod nákladem).

### Časová řada a plánované intervaly

`PriceHistoryRecorder` při každém zápisu zapíše i budoucnost: naplánovaná akce se uloží jako interval `[start, konec)` plus návratový řádek na nominální cenu. **Konec akce proto nepotřebuje cron ani job.**

Invariant: uzavřený řádek se nikdy nemění. Běžícímu se smí posunout jen konec, který ještě nenastal — bez toho by přeplánování kampaně vyrobilo dva intervaly překrývající se v čase.

### Nejnižší cena za 30 dní

Referenční okno končí **začátkem běžící kampaně**, ne přítomností. Akce není součástí své vlastní reference; jinak by se reference vždy rovnala akční ceně a každá oznámená sleva by vyšla 0 %. Badge s procentem se počítá z této reference, ne z nominální ceny.

Produkt nasazený rovnou do akce nemá starší historii — reference pak spadne na aktuální cenu, řádek se zobrazí a badge ne.

## Plnění spec

| AK | Stav | Kde |
|----|------|-----|
| 1. Akce se zapíná a vypíná sama na hranici období | ✅ | `EffectivePriceTest`, `SalePriceCatalogTest` |
| 2. Snímek objednávky drží akční cenu i po skončení akce | ✅ | `CouponOverSalePriceTest` |
| 3. Kupón počítá z akční ceny | ✅ | `CouponOverSalePriceTest` |
| 4. Detail bez JS nese obě ceny i povinný řádek | ✅ | `SaleStorefrontTest` + ruční `curl` |
| 5. Minimum odpovídá skutečnosti včetně přesahujících intervalů | ✅ | `LowestPriceTest` |
| 6. Dědičnost varianty | ✅ | `EffectivePriceTest` |
| 7. Řazení katalogu respektuje akci | ✅ | `SalePriceCatalogTest` |
| 8. Uzavřený historický řádek se nemění | ✅ | `PriceHistoryRecorderTest` |
| 9. Rozpis DPH sedí se součtem objednávky | ✅ | `FeeVatTest` |
| 10. Neplátce uloží dopravu i platbu bez sazby | ✅ | `FeeVatTest` |

## Testy

Nové: `SalePriceSchemaTest` (4), `EffectivePriceTest` (9, unit), `SalePriceCatalogTest` (4), `CouponOverSalePriceTest` (2), `PriceHistoryRecorderTest` (6), `LowestPriceTest` (7), `SaleStorefrontTest` (4), `SaleAdminTest` (4), `FeeVatTest` (5).

Celkem **1514 zelených** (5326 assertions), předtím 1469.

Ruční ověření bez JS proti běžícímu serveru: surové HTML detailu nese `1 432,00 Kč`, přeškrtnutou `1 790,00 Kč`, `−20 %` a řádek „Nejnižší cena za posledních 30 dní: 1 790,00 Kč".

## Odchylky od specifikace

1. **Referenční okno vylučuje běžící kampaň** — spec (i původní plán) mlčky předpokládal minimum přes posledních 30 dní *včetně* akce. Tak by reference vždy padla na akční cenu a badge by nikdy nevznikl. Implementace počítá okno ke *startu kampaně*, což odpovídá dikci § 12a („cena před poskytnutím slevy"). Spec upraven, `LowestPriceTest` to explicitně kotví.
2. **Varianta s vlastní cenou nedědí produktovou akční částku** — vědomé (spec §Chování). Nájemce, který chce zlevnit variantu s vlastní cenou, jí musí dát vlastní `sale_price`.
3. **Omnibus se neuplatňuje na automatická pravidla z vlny 2.6** — veřejně oznámené pravidlo „−10 % na kategorii" je hraniční případ, který vlna vědomě neřeší (`docs/future/slevy-dalsi-kroky.md`).
4. **Řádek s nejnižší cenou je jen na detailu produktu**, ve výpisu je pouze přeškrtnutá cena a akční cena. Riziko je právně nenulové (oznámení slevy padá i ve výpisu), ale zvolené kvůli čitelnosti katalogu — k revizi, pokud přijde právní review.
5. **Backfill historie nevymýšlí minulost** — produkty existující před migrací dostanou jeden otevřený interval od okamžiku migrace. Prvních 30 dní po nasazení tedy reference odpovídá aktuální ceně, ne skutečné historii, kterou nikdo nezaznamenal.
6. **`compare_at_price` zahozen** — sloupec byl v adminu, ale storefront ho nikdy nečetl. Vedle `sale_price` by šlo o dvě pole pro „původní cenu".

## Technický dluh

- **Řádek nejnižší ceny ve výpisu kategorie** (odchylka 4) — dořešit s právním review.
- **`catalogLowestPriceIn30Days()` dělá dotaz per produkt.** Na detailu jeden, ve výpisu se nevolá vůbec. Až se objeví v listingu, bude potřeba dávkové načtení, jinak je to N+1.
- **Page cache** zatím neexistuje; až vznikne, musí ji zápis akční ceny invalidovat (stejný odložený hook jako u bloků homepage z vlny 2.3).
- **Hromadné nastavení akcí** a filtr „ve slevě" — `docs/future/`.
- **Historie neřeší změnu měny** — sloupec `currency` se zapisuje, ale kalkulátor předpokládá jednu měnu obchodu (platí i pro zbytek platformy).

## Pre-deploy checklist

- [ ] `php artisan migrate` — čtyři nové migrace (dvě strukturální, dvě backfill; backfill poplatků je nevratný)
- [ ] Zkontrolovat, že plátci DPH mají v `tax_rates` výchozí sazbu **před** migrací (bez ní se backfill poplatků pro daného nájemce přeskočí a jeho metody zůstanou bez sazby)
- [ ] Upozornit nájemce, že reference „nejnižší cena za 30 dní" začíná běžet od nasazení
- [ ] `npm run build`
