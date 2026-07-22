# Security warnings — DroidShop.cz

Zaznamenávej potenciální bezpečnostní rizika nalezená během vývoje.
Formát: datum, oblast, popis, závažnost, stav (open / mitigated / accepted).

## 2026-07-22 — CSV formula injection ve VAT exportu (CWE-1236)

- **Oblast:** `Modules/Docs/Support/VatCsvWriter.php` (accountant VAT CSV export, vlna 1.6)
- **Popis:** zákaznická billing pole (`odberatel`/name, `ico`, `dic`) jsou jen délkově/typově validovaná při checkoutu, bez omezení znaků. Hodnota jako `=HYPERLINK("http://evil","click")` zapsaná do CSV buňky je Excelem/LibreOffice interpretována jako vzorec a spustí se u účetní nájemce (customer→tenant-staff trust boundary).
- **Závažnost:** important
- **Stav:** mitigated — `VatCsvWriter::neutralize()` prefixuje buňku uvozovkou `'`, pokud první znak je `=`, `+`, `-`, `@`, TAB, CR nebo LF. Aplikováno na textové sloupce (`cislo`, `typ`, `vystaveno`, `duzp`, `odberatel`, `ico`, `dic`, `mena`); peněžní sloupce (`zaklad_*`, `dph_*`, `celkem`) jsou záměrně vyňaty, protože je generuje interně `number_format()` a legitimní záporná částka dobropisu začínající `-` by se jinak nechtěně o-escapovala. Test: `tests/Feature/Modules/Docs/VatExportTest.php`.
