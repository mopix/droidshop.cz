# CSV import a export — další kroky

Vlna 2.8 dodala import a export produktů včetně variant (`Modules/Products/Support/ProductCsv*`, `Services/ProductImporter`, `Jobs/RunProductImport`). Detail: [`docs/as-is/2026-07-28-csv-import.md`](../as-is/2026-07-28-csv-import.md).

Co zůstalo mimo rozsah:

## Obrázky z URL

Sloupec s adresami obrázků, které si server stáhne. Vypadá jako drobnost, ale otevírá **SSRF**: server by na pokyn nájemce tahal libovolnou URL, včetně adres ve vnitřní síti (`169.254.169.254`, `localhost`, privátní rozsahy). Potřebuje:

- allowlist schémat a blokaci privátních rozsahů (včetně kontroly po přesměrování),
- limit velikosti a timeout na stažení,
- MIME kontrolu shodnou s `ProductImageService::ALLOWED_MIMES` (raster-only, žádné SVG),
- rozhodnutí, co s obrázkem, který se stáhnout nepodaří — chyba řádku, nebo produkt bez obrázku.

## Mapování sloupců

Dnes je hlavička pevná; nájemce s dodavatelským feedem si ho musí přejmenovat sám. Mapování by znamenalo novou obrazovku, uložené profily mapování per dodavatel a náhled prvních řádků.

## Plánovaný import z feedu dodavatele

Pravidelné stahování ceníku (URL + interval + přihlašovací údaje). Navazuje na mapování sloupců a na obrázky z URL; navíc potřebuje evidenci běhů na časovači a rozumné chování při výpadku dodavatele (viz precedent `packeta:sync-points` a jeho guard proti prázdnému feedu).

## Mazání produktů importem

Import dnes jen zakládá a aktualizuje. Mazání by potřebovalo explicitní sloupec (`smazat=ano`) a rozhodnutí, co s produktem, na který odkazují objednávky — `ProductWriter::delete()` dělá soft delete právě proto.

## XLSX a další formáty

Excel umí CSV uložit, takže XLSX je pohodlí, ne nutnost. Vyžadovalo by novou závislost (`phpoffice/phpspreadsheet`), což je rozhodnutí o závislostech, ne implementační detail.

## Drobnosti z implementace

- **Protokol chyb se ukládá na `imports/protokol-{id}.csv`** — kdyby někdy šlo spustit tentýž záznam znovu, starý protokol se přepíše.
- **Stažení protokolu čte celý soubor do paměti** (`FileStorage::get`); u tisíců chybných řádků by bylo lepší streamovat, stejně jako to dělá export.
- **Job nemá `failed()` hook** — pád workera nechá běh navždy ve stavu `running`. Precedent, jak to řešit, je v `SendTenantMail` (rozhodnutí 2026-07-20: finální selhání hlásí `failed()`, ne počítadlo pokusů).
- **Obrazovka se neaktualizuje sama** — postup je vidět až po obnovení stránky. Polling nebo broadcast je nadstavba, ne chybějící funkce.
- **Hromadné operace nad výběrem v adminu** (bez CSV) — jiná úloha, ale řeší stejnou bolest.
