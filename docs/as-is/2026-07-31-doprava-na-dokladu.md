# As-is: doprava a poplatek na dokladu + dotažení účetního exportu (vlna 2.12)

Datum: 2026-07-31 · Verze: **0.33.x** (minor uzavře `/finish-wave`) · Větev: `feature/vlna-212-doprava-na-dokladu`

Plán: [`docs/superpowers/plans/2026-07-31-vlna-212-doprava-na-dokladu.md`](../superpowers/plans/2026-07-31-vlna-212-doprava-na-dokladu.md)
Navazuje na: [`docs/as-is/2026-07-30-accounting-export.md`](2026-07-30-accounting-export.md) — uzavírá jeho technický dluh 1 a 2.

## Co vlna přinesla

Faktura, kterou dostane zákazník, teď **sečte**. Do vlny 2.12 tiskla položky za 1 998 Kč a pod nimi „Celkem k úhradě: 2 097 Kč", aniž kdekoli uvedla, odkud rozdíl je: doprava a poplatek za platbu žily jen v součtu a v DPH rekapitulaci, která je členěná po sazbách, ne podle toho, co se prodalo. Doklad, jehož řádky se nesečtou na částku k úhradě, si nemůže zkontrolovat ten, kdo ho platí.

Odhalila to až vlna 2.11, protože účetní export si ten rozdíl musel dopočítávat.

Vedle toho vlna dotáhla dva otevřené body z 2.11 a přidala jeden, který z nich vypadl.

## Mapa změn

| Soubor | Změna |
|--------|-------|
| `Modules/Docs/Services/InvoiceSnapshot.php` | + řádky „Doprava — …" a „Platba — …" ze snímků objednávky; + země dodavatele s fallbackem `CZ` |
| `Modules/Docs/Services/ProformaSnapshot.php` | totéž (staví `items` vlastní cestou, nedeleguje) |
| `Modules/Accounting/Support/DocumentLines.php` | dopočet zbytku nově i pro doklad bez sazeb (neplátce DPH) proti `documents.total` |
| `Modules/Accounting/Support/IsdocFormat.php` | 8 oprav proti oficiálnímu XSD |
| `app/Http/Requests/Tenant/UpdateBillingProfileRequest.php` | + `billing_address.country` (ISO 3166-1 alpha-2, regex s `/D`) |
| `app/Http/Controllers/Tenant/BillingProfileController.php` | předvyplní `CZ` u profilu, který zemi nikdy neměl |
| `resources/js/Pages/Tenant/BillingProfile.vue` | + pole Země |
| `tests/Fixtures/isdoc/` | oficiální XSD 6.0.1 + README s původem |
| `tests/Fixtures/accounting/*.xml` | regenerované golden files (tři řádky místo dvou) |

## Jak to funguje

### Doprava a platba jako řádky

`InvoiceSnapshot` skládá řádky z `orderShippingSnapshot()` a `orderPaymentSnapshot()` ve **stejném tvaru**, jaký položky už měly (`name`, `quantity`, `unit_price`, `tax_rate`, `line_total`), takže se nemusela měnit tabulka, PDF šablona ani modul `accounting` — jen jim začala chodit úplná data. Účtuje se `charged`, ne `price`: liší se, když slevové pravidlo udělalo dopravu zdarma.

Sazba DPH se dohledá přes `TaxRates::findById()`. Kde `tax_rate_id` chybí (neplátce DPH — povinné je od vlny 2.7 jen pro plátce), je sazba **nula**, ne dohadovaný default.

Nulový řádek se zobrazuje (rozhodnutí vlastníka): zvolená doprava má být na dokladu vidět i za 0 Kč. Dobropis nové řádky neguje sám, protože `CreditNoteSnapshot` mapuje přes `items` genericky.

### Historické doklady

Doklad je immutable snímek a PDF už odešla, takže **doklady vystavené dřív zůstávají beze změny** (rozhodnutí vlastníka). V exportu je pokryje dopočet „Doprava a poplatky" z vlny 2.11, který u nových dokladů vyjde na nulu a sám se přestane vkládat.

### Neplátce DPH v exportu

Doklad neplátce má prázdnou `vat_summary`, takže nebylo podle čeho dopočítávat per sazbu. Kombinace „starý tvar + neplátce" proto **tiše vynechávala dopravu** z exportu — účetní by zaúčtovala nižší částku, než se zaplatilo. Nově se v této větvi reconciluje přímo proti `documents.total` s nulovou sazbou; daň, kterou doklad neúčtuje, se nikdy nedopočítává.

### Validace ISDOC proti oficiálnímu XSD

Formát byl psaný z veřejné dokumentace a nikdy neověřený proti schématu; golden files porovnávají výstup se sebou samým, takže vlastní chybu odhalit nemohou. Vendorované XSD 6.0.1 (`tests/Fixtures/isdoc/`, validace přes vestavěný `libxml`, žádná nová závislost) odhalilo **osm skutečných porušení**:

1. chybějící `ElectronicPossibilityAgreementReference`
2. chybějící `RefCurrRate`
3. `TaxPointDate` psaný jako prázdný řetězec místo vynechání (`xs:date`)
4. `PostalAddress` bez `BuildingNumber` a `Country`
5. `ClassifiedTaxCategory` bez `VATCalculationMethod`
6. `TaxSubTotal` používal jméno elementu `ClassifiedTaxCategory` místo `TaxCategory`
7. `TaxSubTotal` bez šesti povinných polí pro zúčtování záloh
8. `LegalMonetaryTotal` bez pěti týchž polí

Každý ISDOC vydaný před touhle vlnou byl odmítnutelný. Pole pro zálohy nejsou zástupné nuly, ale hodnoty odvozené z už spočítaných částek, správné pro doklad bez záloh.

### Země dodavatele

Fakturační profil sbíral jen ulici, město a PSČ, takže by ISDOC v produkci vyšel s prázdnou zemí dodavatele — test to nechytal, protože si ji fixture do adresy vkládal sám. Profil nově zemi má (ISO 3166-1 alpha-2, výchozí `CZ`) a snímek doplní `CZ`, když ji profil ještě nemá. Není to dohad: platforma účtuje v CZK, vyžaduje IČO a jede na českých daňových pravidlech.

Regex má modifikátor `/D` — bez něj PCRE pustí `"CZ\n"`. Projekt na to narazil už u hex barev (`ThemeResolver::sanitizeHex`, rozhodnutí 2026-07-25).

## Testy

Nové: `InvoiceSnapshotTest` (součet řádků = částka k úhradě, pojmenování, nulová doprava, sazba, dobropis), `IsdocSchemaTest` (faktura i dobropis proti XSD), rozšířený `BillingProfileTest` (země, odmítnutí zmetků, znovuuložení starého profilu přes reálný GET → PATCH), doplněné testy dopočtu pro plátce i neplátce a pro starý i nový tvar dokladu.

Opraveno mimo rozsah: dva testy používaly `subMonth()`, což z 31. 7. dělá 1. 7. (červen má 30 dní, datum se normalizuje dopředu), takže sada byla červená **jen 31. v měsíci**. Nahrazeno `startOfMonth()->subDay()`.

## Odchylky od plánu

1. **Přibyl úkol 3b (země v profilu)** — plán s ním nepočítal, vyšel najevo až z validace ISDOC.
2. **Oprava dopočtu pro neplátce DPH** šla nad rámec zadání Tasku 2; implementer ji nahlásil jako „mimo rozsah", ale je to tatáž třída chyby, kterou vlna řeší, tak se opravila hned.
3. **Validace země je `regex` s `/D`, ne `size:2`** jako v checkoutu — `size:2` pustí libovolné dva znaky.

## Technický dluh

1. **`BuildingNumber` a `Country/Name` v ISDOC jsou prázdné** — platforma číslo popisné nesbírá jako samostatné pole a nemá převodník ISO kódu na název země. Schéma to připouští.
2. **Doklady vystavené před touhle vlnou** zůstávají v PDF bez řádku dopravy napořád. V exportu pokryté dopočtem.
3. **`tax_rate` na servisních řádcích je `"21"`, na položkách `"21.00"`** — kosmetika, obojí se tiskne stejně a `VatRateMap` obojí přijme.
4. **Reálný import Pohoda XML do Pohody** zůstává neověřený (potřebuje licenci) — jediný zbývající bod z pre-deploy checklistu vlny 2.11.

## Pre-deploy checklist

- [ ] Ověřit import Pohoda XML do reálné Pohody (ISDOC už hlídá test proti XSD).
- [ ] Po nasazení zkontrolovat, že fakturační profily existujících nájemců mají zemi — dokud ji nevyplní, doklady jedou na `CZ`.
