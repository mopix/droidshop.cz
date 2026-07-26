# As-is: Varianty produktů (vlna 2.4)

Datum: 2026-07-26 · Fáze 2 · Branch: `feature/vlna-2.4-varianty`
Spec: [`docs/superpowers/specs/2026-07-26-vlna-24-varianty-produktu-design.md`](../superpowers/specs/2026-07-26-vlna-24-varianty-produktu-design.md)
Plán: [`docs/superpowers/plans/2026-07-26-vlna-24-varianty-produktu.md`](../superpowers/plans/2026-07-26-vlna-24-varianty-produktu.md)
SDD ledger: [`.superpowers/sdd/2026-07-26-vlna-24-varianty-produktu/progress.md`](../../.superpowers/sdd/2026-07-26-vlna-24-varianty-produktu/progress.md)

## Co vlna přinesla

Nájemce prodává **jeden produkt ve více variantách** (Velikost × Barva × …) s vlastní cenou, skladem a SKU per kombinace. Zákazník variantu vybere na detailu produktu a koupí **i s vypnutým JavaScriptem** — server renderuje osy jako radio/select, formulář se odešle POSTem, server kombinaci resolvne a ověří dostupnost. JS je jen ostrůvek pro živý přepočet ceny (vč. „bez DPH") a dostupnosti nad tím, co server už vyrenderoval.

Produkt bez variant se chová beze změny — varianty jsou per produkt volitelná vrstva, ne povinná.

18 commitů (`d54cf99`..`9f8ebf1`: 10 `feat`, 7 `fix`, 1 `test`), 12 implementačních tasků + tento uzavírací — 7 z těch 18 commitů jsou opravy nalezené v review cyklu, ne šum, viz sekce „Chyby nalezené a opravené v review cyklu" níže. Plný běh testů na hlavě větve (ověřil controller, ne tato dokumentační vlna): **1252 passed / 4094 assertions**.

## Mapa změn (kód)

### Datový model — modul `products`

- `Database/Migrations/2026_07_26_090000_create_product_variant_tables.php` — čtyři nové tabulky:
  - `product_options` (osy) — `tenant_id`, `product_id` (cascade), `name`, `position`; unique `(product_id, name)`.
  - `product_option_values` (hodnoty) — `tenant_id`, `option_id` (cascade), `value`, `position`; unique `(option_id, value)`.
  - `product_variants` (kombinace) — `tenant_id`, `product_id` (cascade), `sku`, `ean`, `price` (nullable, haléře — `null` = zdědit `products.price`), `currency` (default `CZK`), `stock_tracked`, `stock_qty`, `stock_policy`, `active`, `position`.
  - `product_variant_values` (pivot) — `tenant_id`, `variant_id` (cascade), `option_value_id` (cascade); unique `(variant_id, option_value_id)`.
  - `products` + `variant_display` string(16) nullable (`radio`/`select`/`null` = zdědit tenant default).
- Modely: `Models/ProductOption.php`, `Models/ProductOptionValue.php`, `Models/ProductVariant.php`, `Models/ProductVariantValue.php` — všechny `BelongsToTenant`.
- `Services/VariantWriter.php` — jediné místo, které varianty zapisuje: přidání/přejmenování/mazání osy a hodnoty, řazení (`move`), kartézské „Generovat varianty" (idempotentní — existující kombinace zachová, doplní jen chybějící), `updateVariant()` (whitelist zapisovatelných polí), `destroy()`.

### Core kontrakty (`app/Core/Catalog/`)

- `Contracts/CatalogVariant.php` — nový tvar: `getKey()`, `catalogVariantSku()`, `catalogVariantLabel()` („Velikost: M, Barva: červená"), `catalogVariantPrice()` (už s fallbackem), `catalogVariantIsAvailable()`, `catalogVariantSelection()` (`array<option_id, option_value_id>`).
- `Contracts/ProductCatalog.php` rozšířen o `?int $variantId = null` jako poslední parametr `price()`, `decrementStock()`, `incrementStock()` — žádný existující callsite se nezměnil — plus nové `resolveVariant(productId, optionValueIds)`, `findVariantById()`, `variantsFor()`.
- `Contracts/CatalogProduct.php` rozšířen o `catalogHasVariants()`, `catalogPriceFrom()` (min přes aktivní dostupné varianty), `catalogVariantDisplay()`.
- `app/Core/Theme/VariantDisplay.php` — resoluce dědičnosti `products.variant_display` → `tenant_theme.variant_display` → `radio`.

### Košík — modul `checkout`

- `Database/Migrations/2026_07_26_090100_add_variant_id_to_cart_items.php` — `cart_items.variant_id` (NOT NULL, default `0`), přepis `cart_item_unique` na `(tenant_id, cart_id, product_id, variant_id)`.
- `Http/Requests/AddCartItemRequest.php` — `option_value_id` (array, ne `variant_id`).
- `Http/Controllers/CartController.php::add()` — resoluce přes `ProductCatalog::resolveVariant()`; chybějící/neaktivní/nedostupná kombinace = `back()->withErrors()`, žádný zápis do košíku.
- `CartMerger::mergeOnLogin` — opraveno (fix round Task 6), aby `variant_id` prošel i při slučování košíků na přihlášení.
- `CartPricer` počítá řádky přes `price($productId, $context, $variantId)`.

### Objednávka — modul `orders`

- `Database/Migrations/2026_07_26_090200_add_variant_to_order_items.php` — `order_items.variant_id` (nullable, **bez FK**) + `variant_label` (nullable string, snímek „Velikost: M, Barva: červená").
- `OrderPlacer` přepočítá cenu per (produkt, varianta) z katalogu; neshoda → `PriceChanged`; deaktivovaná/nedostupná varianta odmítne placement dřív, než se stihne posoudit cena (pořadí výjimek opraveno ve fix round Task 7).
- Odpis/vrácení skladu míří na variantu, uvnitř téže transakce jako zápis/storno objednávky.
- `OrderEditor` (admin edituje řádky) — opraveno (fix round Task 7, CRITICAL): řádky se teď klíčují `(product_id, variant_id)`, takže editace zachová `variant_id`/`variant_label`/cenu varianty místo přepisu na základní cenu produktu a slučování dvou variant do jednoho řádku.

### Storefront — modul `products` / `checkout` / `storefront`

- `Resources/views/storefront/partials/variant-picker.blade.php` (nový) — server-rendered osy: `<fieldset>/<legend>` + radio, nebo `<label>` + `<select>` dle `catalogVariantDisplay()`; `<script type="application/json" data-variant-matrix>` jako datový zdroj pro JS ostrůvek (matice obsahuje i `net_price`).
- `Resources/views/storefront/show.blade.php` — cena/dostupnost počítaná ze zvolené/předvybrané varianty, JSON-LD `offers` = pole `Offer` per aktivní varianta (bez variant beze změny jediný `Offer`).
- `Modules/Storefront/Resources/views/components/product-card.blade.php` — produkt s variantami zobrazí „od" + `catalogPriceFrom()` místo jediné ceny; karta samotná nemá tlačítko „Přidat do košíku" pro žádný produkt (s variantami ani bez) — jediná akce je odkaz „Detail", takže výběr varianty se řeší až na stránce produktu.
- `resources/js/storefront.js` — nový vanilla JS ostrůvek (žádný framework): na `change` formuláře najde přesnou shodu v embedded matici, aktualizuje `[data-variant-price]` a `[data-variant-net-price]`, přepíná `disabled`/label tlačítka. Bez shody nechá poslední server-rendered stav beze změny. Bundle 1248 B / 606 B gzip, bez Vue.

### Admin — modul `products` + core Vue strom

- `Http/Controllers/ProductVariantAdminController.php` (nový) — tenký, deleguje na `VariantWriter`; 10 endpointů (osy CRUD+move, hodnoty CRUD+move, generate, update varianty, destroy varianty). Každé dítě (option/value/variant) se resolvuje scoped na `product_id`, cizí id 404uje dřív, než se writer zavolá.
- `Http/Requests/StoreProductOptionRequest.php`, `StoreOptionValueRequest.php`, `UpdateProductVariantRequest.php`.
- `routes/admin.php` — 10 nových rout pod `admin.m.products.variants.*`.
- `resources/js/Pages/Modules/Products/Show.vue` — nový tab „Varianty" v existující stránce detailu produktu (ne samostatná Vue komponenta): osy a hodnoty s tlačítky nahoru/dolů, „Generovat varianty", mřížka (cena/SKU/EAN/sklad/aktivní), per-řádkové ukládání s dirty-flag trackingem (viz Technický dluh — opraveno ve fix rounds Task 12).
- `Http/Requests/Tenant/UpdateAppearanceRequest.php` + `Http/Controllers/Tenant/AppearanceController.php` (`/admin/nastaveni/vzhled`) — nové pole `variant_display` (radio/select), ukládá se do `tenant_theme`.

## Plnění spec po sekcích

| Sekce specu | Stav | Poznámka |
|-------------|------|----------|
| Datový model (4 tabulky) | ✅ | přesně dle specu |
| Změny `products`, `tenant_theme` | ✅ | `variant_display` na obou |
| Core kontrakty (`CatalogVariant`, rozšíření) | ✅ | `?int $variantId = null` poslední parametr všude, žádný callsite se nezměnil |
| Server-authoritative resoluce | ✅ | klient posílá `option_value_id[]`, nikdy `variant_id` |
| Sklad na variantě, atomicita | ✅ | atomický `UPDATE`, transakce sdílená se zápisem objednávky |
| Košík (`variant_id`, sentinel, unique) | ✅ | vč. opravy `CartMerger` |
| Objednávka (snapshot, label, sklad) | ✅ | vč. opravy `OrderEditor` (viz Odchylky/dluh) |
| Storefront picker radio/select bez JS | ✅ | |
| „od" cena ve výpisu | ✅ | `catalogPriceFrom()` — pozn. viz Technický dluh (cheapest active, ne cheapest available) |
| JSON-LD `Offer` per varianta | ✅ | |
| JS ostrůvek | ✅ | vanilla, + `net_price` navíc oproti specu (viz Odchylky) |
| Admin: osy, hodnoty, generování, mřížka | ✅ | mřížka bez hromadných akcí (viz dluh) |
| Globální default + přepis per produkt | ✅ | |
| Testy dle tabulky ve specu | ✅ | viz níže |
| Odložené položky do `docs/future/` | ✅ | [`docs/future/varianty-obrazky-a-url.md`](../future/varianty-obrazky-a-url.md) |

## Testy

Nové testovací soubory (podle skutečného stavu v `tests/`, ne podle plánovaných názvů — `VariantDisplayTest` skončil v `tests/Feature/Theme/`, ne `tests/Feature/Modules/Products/`):

| Soubor | Testů | Pokrývá |
|--------|-------|---------|
| `tests/Feature/Modules/Products/VariantSchemaTest.php` | 4 | tenant izolace tabulek, cascade delete |
| `tests/Feature/Modules/Products/VariantCatalogTest.php` | 4 | `catalogHasVariants`, `catalogPriceFrom`, cena s/bez fallbacku |
| `tests/Feature/Modules/Products/VariantResolutionTest.php` | 7 | přesná kombinace, částečný výběr, cizí `option_value_id`, neaktivní varianta, cizí `variant_id`, cross-tenant neviditelnost, `price()` s cizím variant id spadne na cenu produktu |
| `tests/Feature/Modules/Products/VariantStockTest.php` | 6 | odpis z varianty ne z produktu, oversell odmítnut, souběh na posledním kusu, backorder policy, vrácení skladu, untracked varianta je no-op |
| `tests/Feature/Theme/VariantDisplayTest.php` | 5 | default radio, čtení z `tenant_theme`, neznámá hodnota → fallback radio, přepis per produkt vyhrává, dědičnost bez přepisu |
| `tests/Feature/Modules/Checkout/CartVariantTest.php` | 5 | dvě varianty = dva řádky, stejná varianta = navýšení množství, sentinel `0` pro produkt bez variant |
| `tests/Feature/Modules/Orders/OrderVariantTest.php` | 8 | snapshot `variant_id`/cena/label, odpis z varianty, snapshot přežije smazání varianty, deaktivovaná varianta odmítne placement, storno vrací sklad na variantu, **editace zachová variantu** (regresní test na fix Task 7), editace drží dvě varianty stejného produktu odděleně, editace odmítne a nechá objednávku netknutou při zmizelé variantě |
| `tests/Feature/Modules/Products/VariantStorefrontTest.php` | 9 | server-rendered osy, POST bez JS vloží správnou variantu, cizí `option_value_id` odmítnut, „od" cena ve výpisu, JSON-LD `Offer` per varianta, radio vs. select render, **net price zachována i pro produkt bez variant** (regresní test na fix Task 8), embedded matice pro JS ostrůvek vč. `net_price` |
| `tests/Feature/Modules/Products/VariantWriterTest.php` | 5 | generování idempotentní, přejmenování hodnoty nechá varianty na místě, smazání hodnoty smaže jen dotčené varianty |
| `tests/Feature/Modules/Products/VariantAdminTest.php` | 21 | HTTP vrstva všech 10 endpointů, host bloknut, cross-tenant member 403, cross-tenant/cross-product id 404, validace ceny, **6 regresních testů na `products.edit` guard** (fix Task 11) |

**Co NENÍ pokryto:**
- **E2E (Playwright)** — projekt nemá Playwright konfiguraci vůbec (STATUS.md: „není", blokováno omezením certifikátu); nákup s variantou bez JS je ověřen jen na úrovni Feature testů (skutečný POST, ne prohlížeč), ne end-to-end klikacím scénářem.
- Hromadné akce v mřížce variant nemají test — protože je funkce sama nemá (viz Technický dluh).
- Fasetový filtr podle osy nemá test — funkce neexistuje (`docs/future/`).
- JS ostrůvek (`resources/js/storefront.js`) nemá žádný JS test runner v projektu — ověřen jen manuálně (build, velikost bundlu, `grep` na absenci Vue) a nepřímo přes HTML assertions na embedded matici.
- `catalogVariantSku()` a `catalogVariantDisplay()` (na `CatalogVariant`/`CatalogProduct`) nemají dedikovaný unit test (ledger Task 2, minor, deferred).

## Odchylky od specifikace

1. **JS ostrůvek je vanilla JS v `resources/js/storefront.js`, ne Alpine, jak psal spec.** Projekt Alpine nemá (`package.json` = Vue + Inertia + Tailwind) a závislosti se nemění bez souhlasu uživatele (CLAUDE.md pravidlo). Ostrůvek je napsaný stejným stylem jako existující galerie ve stejném souboru — čte `<script type="application/json" data-variant-matrix>`, na `change` najde přesnou shodu a aktualizuje jen text/atributy, žádná cenová aritmetika v JS.

2. **`cart_items.variant_id` je NOT NULL se sentinelem `0`, `order_items.variant_id` je nullable bez FK — vědomě asymetrické.** V košíku `NULL` v unique indexu (MySQL i SQLite) znamená „vždy odlišné", takže produkt bez variant by šel do košíku vícekrát jako samostatné řádky — přesně to, čemu má `cart_item_unique` zabránit. `order_items` žádný unique index nemá, takže tam `NULL` čte poctivě jako „bez varianty" a sentinel by byl zbytečný. FK na variantu v `order_items` schází záměrně, stejně jako u `product_id` — objednávka je snímek, který musí přežít smazání varianty i produktu.

3. **`variant_display` sedí na `tenant_theme`, ne v `settings_schema` modulu `products`.** `SettingsService` dnes nemá žádnou admin obrazovku (`Modules/Docs/settings.json` se needituje přímo) — postavit **první** obrazovku modulových nastavení by byl scope navíc mimo cíl vlny. Pole je proto vedle loga/barev na `/admin/nastaveni/vzhled`, přestože jde o katalogovou prezentaci, ne branding. Vědomý kompromis ze specu, ne improvizace při implementaci — až vznikne obrazovka modulových nastavení, pole se přesune.

4. **Cross-tenant admin přístup vrací 403, ne 404, jak předpokládal plán.** Signed-in uživatel, který **není členem** tenanta, dostane `403` — to je existující konvence `EnsureTenantMember` (routa už tím, že prošla modulovou branou, prozrazuje svou existenci; viz i `DocumentAdminTest`). Task 11 implementoval a otestoval přesně tohle, ne 404, jak plán psal — vědomá oprava plánu proti realitě middlewaru, ne odchylka od ní. **Cizí *produkt* id** (přihlášený člen tenanta A, ale produkt patří tenantovi B) 404uje beze změny — tenant-scoped route-model binding ho prostě nenajde, žádný kód navíc.

5. **JS ostrůvek navíc aktualizuje i cenu „bez DPH" (`net_price`), což spec explicitně nezmiňoval.** Server-rendered matice (`variant-picker.blade.php`) dostala nové pole `net_price` = `$product->rate()->net($variant->catalogVariantPrice())->format()` — přesně stejná cesta přes `TaxRate`, jakou používá `show.blade.php` pro počáteční render (rozhodnutí 2026-07-20: převody DPH sedí na `TaxRate`, ne na `Money`). Bez tohohle by po přepnutí varianty JS aktualizoval hrubou cenu, ale nechal viset zastaralou čistou cenu vedle ní — nekonzistentní pár čísel na obrazovce. DPH konverze zůstává na serveru; JS jen zobrazí druhý předpočítaný řetězec.

## Chyby nalezené a opravené v review cyklu (pro úplnost, ne dluh)

Tyto nejsou otevřený dluh — byly nalezeny reviewem a opraveny v rámci vlny, uvádím je, protože ukazují na křehká místa, kam se má budoucí práce dívat pozorně:

- **Task 7 (CRITICAL):** `OrderEditor::edit()` mazal `variant_id`/`variant_label` a přeceňoval na základní cenu, navíc slučoval dvě varianty jednoho produktu do jednoho řádku. Opraveno — řádky se klíčují `(product_id, variant_id)`.
- **Task 8 (Important):** oprava „bez DPH" ceny na detailu produktu nechtěně smazala net-price řádek pro **všechny** produkty, ne jen s variantami. Opraveno, regresní test přidán.
- **Task 11 (CRITICAL):** šest z deseti admin endpointů (`destroyOption`, `moveOption`, `destroyValue`, `moveValue`, `generate`, `destroy`) nemělo kontrolu `products.edit` — personál jen s `products.view` mohl mazat osy/hodnoty/varianty a přegenerovat matici. Opraveno, 6 regresních testů.
- **Task 12 (Important → fix round 2):** mřížka variant v adminu vázala `v-model` přímo na prop z Inertia — jakákoli plná návštěva stránky přepsala neuložené úpravy v jiných řádcích; první oprava navíc zavedla regresi (needitovaný řádek se nikdy nesynchronizoval znovu se serverem, takže mohl vrátit serverem mezitím změněný sklad). Finální oprava: per-řádkový dirty flag nastavený na každou editovatelnou kontrolu, mazaný jen po úspěšném uložení.

## Technický dluh (z ledgeru, akční seznam)

- **Hromadné akce v mřížce variant** („nastavit cenu/sklad všem") — spec je zmiňuje v admin sekci, plán je nerozepsal jako samostatný krok; mřížka dnes ukládá po řádcích. Malé UI rozšíření nad existujícím `updateVariant` endpointem, ne nová datová vrstva.
- **`catalogPriceFrom()` bere nejlevnější *aktivní* variantu, ne nejlevnější *skladem dostupnou*.** „Od" cena ve výpisu může jmenovat variantu, která je momentálně vyprodaná. (Task 2, minor, deferred „revisit after Task 4" — revize se nestala.)
- **`decrementStock`/`incrementStock` bez `variantId` u produktu, který varianty má, projde beze změny na produktu.** Žádný současný callsite takhle nevolá (`OrderPlacer` variantu vždy předá), ale kontrakt to nezakazuje — ostrý roh, ne aktivní bug.
- **`incrementStock` u chybějící varianty tiše vrátí (no-op), `decrementStock` u chybějící varianty vyhodí.** Asymetrie mezi metodami stejného kontraktu (Task 4, plan-level).
- **`InsufficientStock` hláška jmenuje jen produkt, nikdy variantu** — nájemce v logu/erroru nepozná, které kombinaci sklad došel.
- **Duplicitní guard+update blok** napříč odpisem produktu/varianty a oběma větvemi přírůstku (Task 4, plan artifact) — kandidát na extrakci sdílené metody.
- **Concurrency test na skladu je jednovláknový**, mechanicky totožný s testem na oversell — neověřuje skutečný souběh dvou requestů (Task 4).
- **Chybí index pro fasetový filtr** podle osy — `product_variant_values` má index `(tenant_id, option_value_id)` pro resoluci jedné kombinace, ne pro agregaci „kolik produktů má tuto hodnotu" napříč výpisem. Bez dopadu, dokud filtr neexistuje (`docs/future/`).
- **Per-tenant page cache invalidace po změně variant** — page cache jako mechanismus zatím vůbec neexistuje (stejný stav jako u blokové homepage 2.3); až vznikne, bude potřeba invalidovat výpis kategorie/homepage po úpravě ceny/skladu varianty.
- **JSON-LD emituje `"sku":null`** pro variantu bez SKU (Task 8, minor) — validní JSON-LD, ale stojí za zvážení, jestli klíč radši vynechat.
- **Žádný per-hodnotový „vyprodáno" indikátor před odesláním formuláře** — badge „Skladem"/„Vyprodáno" je spočítaný serverem z „aspoň jedna varianta dostupná" a po výběru se needituje jinak než přes JS ostrůvek (Task 9, minor) — může tedy chvíli ukazovat „Skladem" nad zašedlým tlačítkem, než se JS spustí.
- **České texty duplikované** mezi `storefront.js` a `show.blade.php`; výraz pro čistou cenu duplikovaný mezi `show.blade.php` a `variant-picker.blade.php` (Task 9, minor) — kandidát na sdílenou konstantu/partial.
- **`generate()` obaluje každý nový řádek do vlastní transakce**, ne do jedné vnější — bezpečné, protože generování je idempotentní a retry se sám doléčí, ale ne atomické jako celek (Task 10, minor).
- **`generate()`'s zero-created hláška** vždy hlásí „Všechny kombinace už existují." i když produkt nemá žádné osy vůbec (Task 11, minor).
- **`variant_label` je defaultní `varchar`** — víceosá kombinace s dlouhými názvy os/hodnot se teoreticky může přiblížit limitu (Task 7, minor).
- **Chybí test na měkký smazání (soft-delete) nechává varianty na místě** — otestován jen `forceDelete` větev cascade (Task 1, minor).
- **`product_variants.currency` sloupec** je v migraci zdůvodněný nepřesně (report cituje důvod, který neodpovídá tomu, že `MoneyCast` stejně padá na `'CZK'` fallback) — sloupec samotný je neškodný, jen kopíruje vzor tabulky `products` (Task 1, minor).

## Bezpečnost a tenant izolace

- Všechny 4 nové tabulky mají `tenant_id` + `BelongsToTenant` (vč. `ProductVariantValue`, opraveno hned v Task 1 fix round — pivot model zpočátku izolaci neměl).
- Server-authoritative resoluce: klient posílá `option_value_id[]`, nikdy `variant_id`; `resolveVariant()` ověřuje příslušnost k produktu i tenantovi.
- Cena z klienta se nikdy nebere (žádné pole `variant_id`/`unit_price` v `AddCartItemRequest`, mass assignment ho nemůže propašovat).
- Sklad — atomický `UPDATE`, transakce sdílená se zápisem/stornem objednávky.
- Admin endpointy — po fix Task 11 všech 10 endpointů gated `products.edit`; cross-tenant member 403, cross-tenant/cross-product id 404.

## Pre-deploy

- Migrace jsou čistě aditivní, žádný ruční backfill kromě defaultu `tenant_theme.variant_display = 'radio'` (sloupcový default, ne migrace dat) a naplnění `cart_items.variant_id = 0` u existujících řádků (sloupcový default při `ALTER`).
- Žádná nová závislost v `composer.json`/`package.json` — ověřit `npm run build` po merge (storefront bundle beze změny frameworku, 1248 B / 606 B gzip).
- `./vendor/bin/pint --dirty` běžel čistě po každém tasku (ledger).
