# As-is: koruny v administraci, rozměry produktu, obrázky (vlna 3.8)

Datum: 2026-08-09 · Verze: **0.43.0** · Větev: `feature/vlna-38-produkt-a-ceny`

Zadání: [spec](../superpowers/specs/2026-08-09-vlna-38-produkt-a-ceny-design.md) · [plán](../superpowers/plans/2026-08-09-vlna-38-produkt-a-ceny.md)

## Co bylo špatně

Čtyři nálezy vlastníka z jedné obrazovky.

**Ceny se zadávaly v haléřích.** Vnitřní jednotka se protlačila až do formuláře — nájemce prodávající za 1 790 Kč psal `179000`. CSV import přitom od vlny 2.8 pracuje s korunami.

**Produkt neměl rozměry**, jen hmotnost.

**Obrázky nešlo seřadit.** `ProductImageService::reorder()` i routa existovaly od vlny 1.2 a nikdo je nikdy nezavolal.

**Tlačítka Uložit a Smazat produkt se kreslila i nad panely, kam nepatří** — proto vlastník přehlédl, že „Nastavit jako hlavní" existuje.

## Kde to leží

| Vrstva | Soubory |
|---|---|
| Převod jednotek | `app/Core/Money/MoneyInput.php`, `ConvertsMoneyInput.php`, `Exceptions/InvalidMoneyInput.php` |
| Formuláře | `Modules/Products/Http/Requests/{StoreProductRequest,UpdateProductVariantRequest}.php`, `Modules/Shipping/Http/Requests/*`, `Modules/Discounts/Http/Requests/*`, `app/Http/Requests/Tenant/UpdateModuleSettingsRequest.php` |
| Rozměry | migrace `products.length_mm/width_mm/height_mm`, `Product::hasDimensions()`, `CatalogProduct::catalogDimensionsMm()`, `OrderPlacer`, `Carrier`, `PacketaCarrier`, `PacketaClient` |
| Obrazovka | `resources/js/Pages/Modules/Products/Show.vue` |
| Storefront | `Modules/Products/Resources/views/storefront/show.blade.php` |

## Rozhodnutí, která stojí za připomenutí

**Nikdy float na float.** `(int) (0.07 * 100)` je 6, ne 7 — klasická cesta, jak cena přijde o haléř a nikdo si toho nevšimne dřív než zákazník. `MoneyInput` čte desetinnou část jako vlastní číslice a přičítá ji celočíselně.

**Prázdné není nula.** Nevyplněná nákupní cena znamená „nevyplněno", nula znamená „zdarma". Kdyby to splynulo, e-shop by rozdával produkty.

**Převod běží v `prepareForValidation()`, ne po validaci.** `lt:price` porovnává akční cenu s pultovou; převod až potom by porovnával koruny s haléři a každá akční cena by prošla.

**Sleva se převádí jen u pevné částky.** U procentní je `value` promile (10 % je 100) a průchod korunovým parserem by z desetiny slevy udělal desetinásobek košíku.

**Pole jsou `type="text"` s `inputmode="decimal"`.** Desetinná čárka není v `type="number"` platná, a čárka je to, co tady člověk píše.

**Rozměry jdou dopravci jen když je zásilka jeden produkt.** Sečíst tři krabice do jedné sady vnějších rozměrů nejde bez znalosti, jak jsou zabalené, a odhad podaný dopravci je horší než mlčení — rozhoduje o tom, jestli je zásilka nadrozměrná. Snímkují se při odeslání objednávky vedle hmotnosti, takže pozdější úprava produktu už podanou zásilku nepředefinuje.

**Všechny tři rozměry, nebo žádný.** Dopravce, kterému řeknete jen délku, neví víc než ten, kterému neřeknete nic.

## Odchylky od plánu

| Odchylka | Proč |
|---|---|
| `PacketaClient` umí jedno zanoření XML | Packeta bere `size` jako skupinu; obecný rekurzivní serializér by byl aparát pro případ, který neexistuje |
| E2E nezkouší skutečné nahrání ani řazení | Viz technický dluh 1 — sráží to dev server pro všechny další testy |
| Výpis produktů posílá dál haléře | Jen se zobrazuje a formátuje si to sám; koruny jsou pro pole, do kterých se píše |

## Testy

| Soubor | Co hlídá |
|---|---|
| `tests/Unit/MoneyInputTest.php` | 11 testů: obě oddělovače, tisícové mezery, `0,07` = 7, prázdno ≠ nula, round trip |
| `tests/Feature/Modules/Products/PriceInKorunasTest.php` | produkt, akce, nákupní cena, `lt:price`, prázdno, překlep, nastavení modulu |
| `tests/Feature/Modules/Products/ProductDimensionsTest.php` | uložení, nepovinnost, nesmyslná hodnota, storefront, neúplná sada |
| `tests/Feature/Modules/Products/ProductImageOrderTest.php` | pořadí, storefront, page cache, cizí id, oprávnění |
| `tests/Feature/Modules/Packeta/PacketaCarrierTest.php` | rozměry odejdou, a bez nich se neodešle prázdný element |
| `e2e/tests/product-images.spec.ts` | tlačítka formuláře mimo panel obrázků, reakce plochy na přetažení |

Celá sada: 2157 PHPUnit, 43 Playwright.

**Šest existujících testů mělo po převodu špatnou premisu** (posílaly do formuláře haléře). Opraveny na koruny; `ProductWriter` a factory dál pracují v haléřích, protože to nejsou formuláře.

## Technický dluh

1. **E2E nesmí sáhnout na uložený obrázek.** Jakmile demo produkt nese obrázek, storefront si ho stáhne vedle stránky a `php artisan serve` zavře spojení dalšímu testu (`ERR_CONNECTION_CLOSED`). `PHP_CLI_SERVER_WORKERS=4` nepomohlo. **Nedohledáno do konce** — nahrání i řazení proto jede přes HTTP v PHPUnit. Týká se to i lokálního vývoje: server může spadnout po nahrání obrázku.
2. **Rozměry per varianta nejsou.** Stejně jako hmotnost.
3. **Rozměry jde poslat jen u jednopoložkové zásilky.** Vícepoložková by potřebovala balicí logiku.
4. **Cizí sazba v katalogu.** `catalogDimensionsMm()` přibylo do jádrového kontraktu; implementuje ho jen `Product`.

## Pre-deploy

- [ ] `php artisan migrate` (rozměry produktu)
- [ ] `npm run build`
- [ ] Projít formulář produktu a ověřit, že uložená cena sedí na to, co bylo zadáno
