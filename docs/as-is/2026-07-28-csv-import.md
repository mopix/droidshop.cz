# As-is: CSV import a export produktů (vlna 2.8)

Datum: 2026-07-28 · Verze: **0.25.x** (patch bumpy zvedá pre-commit hook; minor uzavře `/finish-wave`) · Větev: `feature/vlna-28-csv-import` · **1565 testů (5474 assertions)** zelených

Spec: [`docs/superpowers/specs/2026-07-28-vlna-28-csv-import-design.md`](../superpowers/specs/2026-07-28-vlna-28-csv-import-design.md)
Plán: [`docs/superpowers/plans/2026-07-28-vlna-28-csv-import.md`](../superpowers/plans/2026-07-28-vlna-28-csv-import.md)

## Co vlna přinesla

Nájemce naplní a udržuje katalog hromadně: stáhne si katalog jako CSV, upraví ho v Excelu a nahraje zpět. Import zakládá i aktualizuje produkty a varianty podle SKU, zařazuje je do existujících kategorií, respektuje limit tarifu a chybné řádky přeskočí do protokolu, který jde stáhnout, opravit a nahrát znovu.

Export produkuje **přesně ten formát, který import přijme** — a `CsvRoundTripTest` to hlídá.

## Mapa změn

### Modul `Modules/Products/`

| Soubor | Role |
|--------|------|
| `Support/ProductCsvSchema.php` | Jediná pravda o sloupcích, stavech, skladových politikách a převodu peněz (`money()`/`formatMoney()`) |
| `Support/ProductCsvParser.php` | Surové CSV → asociativní řádky; BOM, `;` i `,`, volné pořadí sloupců |
| `Support/ProductRowValidator.php` | Validace řádku → seznam českých hlášek |
| `Support/ProductCsvExporter.php` | Katalog → řádky (generátor nad `lazy(200)`) |
| `Services/ProductImporter.php` | Aplikace jednoho řádku, upsert dle SKU, kategorie, limit tarifu, suchý běh |
| `Services/VariantWriter.php` | +`upsertVariant(Product, array $axes, array $attributes)` |
| `Jobs/RunProductImport.php` | Dávkový běh nad souborem, počty, chybové CSV |
| `Models/ProductImport.php` | Záznam běhu |
| `Http/Controllers/ProductImportController.php` | Obrazovka, upload, stažení protokolu |
| `Http/Controllers/ProductExportController.php` | Streamovaný export |
| `Http/Requests/StoreProductImportRequest.php` | Validace uploadu |
| `Database/Migrations/…_create_product_imports.php` | Tabulka běhů |
| `routes/admin.php` | `/export`, `/import`, `/import/{import}/protokol` — **nad** `/{product}` |
| `module.json` | Nav položka „Import / export" |

### Ostatní

- `config/products.php` (nový) — `products.import.max_size_kb`, `products.import.chunk`
- `resources/js/Pages/Modules/Products/Import.vue` — upload, přepínač suchého běhu, historie běhů, seznam sloupců

## Jak to funguje

### Formát

Hlavička je česká, pořadí sloupců volné, oddělovač `;`, UTF-8 s BOM, peníze v korunách s desetinnou čárkou. Řádek nese `typ` = `produkt` nebo `varianta`; varianta se váže přes `varianta_rodic_sku` a osy zapisuje jako `Velikost:M|Barva:černá`. Kategorie stejně: `Elektronika > Klávesnice|Akce`.

Parser je shovívavý (BOM, `,` jako oddělovač, `.` jako desetinná tečka), export přísný.

### Import

Upload → privátní disk → `product_imports` (`pending`) → job. Job čte řádek po řádku, každý platný aplikuje **v jedné transakci**; chybný přeskočí a zapíše do protokolu s číslem řádku, SKU a důvodem. Počty se zapisují průběžně po dávkách, takže obrazovka ukazuje postup.

Zápis jde výhradně přes `ProductWriter`/`VariantWriter` — import tedy dostane stejnou sanitizaci HTML, unikátní slug, 301 redirect při změně slugu a **zápis do historie ceny** (Omnibus, vlna 2.7) jako ruční editace v adminu.

### Export

Streamovaná odpověď nad `lazy(200)`; produkt i každá jeho varianta mají vlastní řádek. Nákupní cena je ve výstupu jen pro uživatele s `products.costs`.

## Plnění spec

| AK | Stav | Kde |
|----|------|-----|
| 1. Import založí produkty, běh je v historii s počty | ✅ | `ProductImportAdminTest`, `RunProductImportTest` |
| 2. Opakovaný import aktualizuje, nezakládá duplikáty | ✅ | `ProductImporterTest` |
| 3. Chybný řádek nezastaví běh, protokol nese důvod | ✅ | `RunProductImportTest` |
| 4. Suchý běh nezapíše nic | ✅ | `ProductImporterTest`, `RunProductImportTest`, `ProductImportAdminTest` |
| 5. Round-trip nechá katalog beze změny | ✅ | `CsvRoundTripTest` |
| 6. Varianta podle `varianta_rodic_sku` včetně os | ✅ | `ProductImporterTest`, `UpsertVariantTest` |
| 7. Neexistující kategorie = chyba řádku | ✅ | `ProductImporterTest` |
| 8. Vyčerpaný limit shodí jen svůj řádek | ✅ | `ProductImporterTest` (plus test, že aktualizace projde i při plném tarifu) |
| 9. Import zapíše cenu do historie | ✅ | `ProductImporterTest`, `UpsertVariantTest` |
| 10. Nákupní cena jen s `products.costs` | ✅ | `ProductCsvExportTest` (obě větve — owner i staff bez práva) |
| 11. Soubor ani protokol nejsou veřejné, cizí běh 404 | ✅ | `ProductImportAdminTest` |
| 12. HTML v popisu se sanitizuje | ✅ | `ProductImporterTest` |

## Testy

Nové: `ProductImportSchemaTest` (3), `ProductCsvParserTest` (7, unit), `ProductRowValidatorTest` (9), `UpsertVariantTest` (3), `ProductImporterTest` (13), `RunProductImportTest` (4), `ProductCsvExportTest` (5), `ProductImportAdminTest` (6), `CsvRoundTripTest` (1).

Celkem **1565 zelených** (5474 assertions), předtím 1514.

## Odchylky od specifikace

1. **Prázdná buňka při aktualizaci neměže hodnotu** — znamená „neměnit". Vymazat hodnotu importem tedy nejde, jen ji přepsat. Chrání před tím, aby prázdný sloupec v Excelu smazal popisy celého katalogu.
2. **Výrobce se importem zakládá, kategorie ne.** Výrobce je plochý štítek (a `ProductWriter::manufacturer()` už firstOrCreate uměl), kategorie je sdílený strom, kde překlep vytvoří větev k ručnímu úklidu.
3. **Import nikdy nemaže produkty.** Řádek chybějící v souboru se nedotkne existujícího produktu.
4. **Obrázky mimo rozsah** — stahování z URL je SSRF plocha, samostatná vlna.
5. **Ruční zkouška v adminu neproběhla** — funkčnost je pokrytá feature testy včetně uploadu přes HTTP (`ProductImportAdminTest`), ale skutečné kliknutí v prohlížeči jsem neudělal.

## Technický dluh

- **Protokol se přepisuje při opakovaném běhu téhož importu** — cesta je `imports/protokol-{id}.csv`, takže re-run stejného záznamu (dnes nemožný z UI) by starý přepsal.
- **Stažení protokolu čte celý soubor do paměti** (`FileStorage::get`) — u tisíců chyb by bylo lepší streamovat.
- **Import nepodporuje mazání** ani hromadné operace nad výběrem.
- **Obrazovka se neaktualizuje sama** — postup běhu uvidí nájemce až po obnovení stránky.
- **Job nemá `failed()` hook** — pád workera nechá běh ve stavu `running` navždy.

## Pre-deploy checklist

- [ ] `php artisan migrate` — jedna nová tabulka `product_imports`
- [ ] Ověřit, že běží `queue:work` — bez workera zůstane import ve stavu `pending` (na `sync` driveru proběhne inline)
- [ ] Zkontrolovat velikost uploadu v PHP (`upload_max_filesize`, `post_max_size`) proti `products.import.max_size_kb` (výchozí 5 MB)
- [ ] `npm run build`
