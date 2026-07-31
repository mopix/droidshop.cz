# As-is: účetní export do Pohody a ISDOC (vlna 2.11)

Datum: 2026-07-30 · Verze: **0.31.x** (minor uzavře `/finish-wave`) · Větev: `feature/vlna-211-accounting-export` · **1734 testů** zelených (6126 assertions)

Spec: [`docs/superpowers/specs/2026-07-30-vlna-211-accounting-export-design.md`](../superpowers/specs/2026-07-30-vlna-211-accounting-export-design.md)
Plán: [`docs/superpowers/plans/2026-07-30-vlna-211-accounting-export.md`](../superpowers/plans/2026-07-30-vlna-211-accounting-export.md)

## Co vlna přinesla

Nájemcova účetní dostávala z e-shopu jen CSV se souhrnem DPH — doklady do účetního programu musela opsat. Modul `accounting` vydá tytéž doklady ve formátech, které účetní software importuje: **Pohoda XML** (dávka za období) a **ISDOC 6.0.1** (ZIP, jeden soubor per doklad), plus jednotlivý doklad jako `.isdoc` ze seznamu dokladů.

Je to zároveň **první nový premium modul**. Premium tarif do teď odlišoval jen `discounts` a limity.

## Mapa změn

### Nový modul `Modules/Accounting/` (`level: premium`, permission `accounting.export`)

| Soubor | Role |
|--------|------|
| `module.json` | Manifest; `premium`, bez `requires` na `docs`, `settings_permission: accounting.export`, nav „Účetní export" |
| `settings.json` | Pět polí konfigurace Pohody (předkontace faktury/dobropisu, členění DPH, středisko, činnost) |
| `Contracts/AccountingFormat.php` | `key`/`label`/`extension`/`mime`/`filenameFor`/`writeOne`/`writeBatch` |
| `Support/AccountingFormats.php` | Registry formátů podle klíče (precedent `PaymentGatewayRegistry`) |
| `Support/PohodaXmlFormat.php` | `dat:dataPack` dávka |
| `Support/IsdocFormat.php` | Jeden `.isdoc`, dávka jako ZIP, deterministické UUID |
| `Support/DocumentLines.php` | Přepočet řádků dokladu na čisté částky a dopočet zbytku (doprava a poplatky) |
| `Support/DocumentAmounts.php` | Haléře → desetinné číslo s tečkou, celočíselně |
| `Support/VatRateMap.php` | 21/12/0 → `high`/`low`/`none`, jinak výjimka |
| `Exceptions/UnsupportedVatRate.php` | Renderuje se jako 422 s číslem dokladu |
| `Http/Controllers/AccountingExportController.php` | `index`, `export`, `isdoc` |
| `Http/Requests/ExportDocumentsRequest.php` | Období, formát, strop počtu dokladů |
| `routes/admin.php` | `admin.accounting.index|export|isdoc` |

### Mimo modul

| Soubor | Změna |
|--------|-------|
| `app/Core/Documents/Contracts/DocumentLedger.php` | + `findTaxDocument(string $number, string $type): ?DocumentView` |
| `app/Core/Documents/NullDocumentLedger.php` | + vrací `null` |
| `Modules/Docs/Services/EloquentDocumentLedger.php` | + implementace (filtr daňových typů, `whereNotNull('taxable_at')`) |
| `Modules/Docs/Http/Controllers/DocumentAdminController.php` | + prop `accountingEnabled` (modul **i** právo) |
| `resources/js/Pages/Modules/Docs/Index.vue` | Sloupec ISDOC jen pro shop s modulem, odkaz jen u daňových dokladů |
| `resources/js/Pages/Modules/Accounting/Index.vue` | Formulář období + formát (plain GET, ne Inertia) |
| `config/accounting.php` | `max_documents` (5 000) |

## Jak to funguje

### Výběr dokladů

Dávka jde přes `DocumentLedger::taxableBetween()` — období podle **DUZP**, jen `invoice` a `credit_note`. Stejná politika jako CSV VAT export z vlny 1.6; dvě exportní cesty ze stejných dat nesmí tvrdit každá něco jiného. Jednotlivý doklad jde přes `findTaxDocument($number, $type)`; **typ je povinný**, protože číslo je od vlny 1.6 unikátní jen v rámci `(tenant, type)` — jinak by stará URL vydala fakturu na číslo dobropisu.

### Ceny jsou ve snímku s DPH, formáty chtějí bez DPH

Nejdůležitější věc celé vlny a shodou okolností i nález, který zachytilo až finální review: `documents.items` nese `unit_price` a `line_total` **včetně DPH** (tak se v projektu ceny ukládají), kdežto Pohoda i ISDOC mají v odpovídajících polích částky **bez DPH**. První verze psala hrubé částky do čistých polí, takže by import seděl zhruba o 21 % výš — a golden files to nezachytily, protože porovnávaly jen strukturu.

`DocumentLines` teď převádí přes `TaxRate::net()` celočíselně, Pohoda navíc značí `inv:payVAT`, ISDOC plní čisté i hrubé varianty polí. Řádky, `TaxTotal` i `documents.total` musí sedět na haléř; hlídají to hodnotové aserce v testech, které si čísla počítají nezávisle na exportéru.

### Dopočtený řádek „Doprava a poplatky"

Snímek dokladu **nikdy nenesl dopravu ani poplatek za platbu jako položku** — jsou jen v součtu a v DPH rekapitulaci. Exportované řádky proto neseděly s `documents.total`. Modul dopočítá rozdíl per sazba (`(base + vat) − Σ line_total`, celočíselně) a vydá ho jako jeden řádek. Doklad, jehož řádky už sedí, žádný řádek navíc nedostane.

Je to léčba symptomu: skutečná díra je v `Modules\Docs\Services\InvoiceSnapshot`, který dopravu nesnímkuje — a **týká se i zákaznického PDF**, kde se řádky faktury nesečtou na „Celkem k úhradě". To je starší chyba mimo rozsah téhle vlny, viz Technický dluh.

### Sazby DPH a chyby

`VatRateMap` mapuje jen přesné 21/12/0; cokoli jiného zastaví export výjimkou, která **jmenuje doklad**, a vrací se jako 422 s českou hláškou. Zaokrouhlování bylo odstraněno, takže 11,5 % neprojde jako `low`.

Prázdné období vrátí hlášku a **žádný soubor** — prázdný soubor u účetní vypadá jako „nic se neprodalo". Nad 5 000 dokladů se export odmítne validační chybou u pole období; chyba je vidět na obrazovce, ne jen v session.

### Doručení

Pohoda XML se skládá `XMLWriter`em (nikdy konkatenací — do dokladu jdou názvy produktů a adresy psané tenanty a jejich zákazníky) a posílá jako soubor; ISDOC dávka jde do temp ZIPu s `deleteFileAfterSend`, takže nespotřebovává úložiště nájemce. Jméno souboru v archivu nese **typ i číslo** (`faktura-2026001.isdoc`), protože faktura a dobropis mohou nést stejné číslo a jeden by druhý v archivu přepsal. `ZipArchive::addFromString()` i `close()` se kontrolují — tiše neúplný archiv je u účetnictví horší než pád.

ISDOC `UUID` je deterministické (v5 nad `tenant_id` + typ + číslo): importní nástroje na něm dedupují, takže opakovaný export nesmí vyrobit druhý doklad.

### Audit

Každý export zapíše `accounting.exported` (období, formát, počet dokladů) — **až po úspěšném vygenerování**. Záznam o tom, že účetní data odešla z e-shopu, nesmí vzniknout u exportu, který spadl.

## Plnění spec (akceptační kritéria)

| AK | Stav | Kde |
|----|------|-----|
| 1 — Pohoda XML za období | ✅ | `AccountingExportTest` |
| 2 — ISDOC ZIP, typ v názvu souboru | ✅ | `IsdocFormatTest` |
| 3 — jednotlivý doklad, cizí číslo 404, proforma 404 | ✅ | `AccountingExportTest` |
| 4 — 403 bez práva, 404 bez modulu | ✅ | `AccountingModuleTest` |
| 5 — dobropis jako `issuedCreditNotice` / opravný doklad, proforma nikdy | ✅ | oba formátové testy |
| 6 — předkontace se projeví, prázdná se vynechá | ✅ | `PohodaXmlFormatTest` |
| 7 — neznámá sazba zastaví export a jmenuje doklad (422) | ✅ | `AccountingIsolationTest`, `AccountingExportTest` |
| 8 — prázdné období nevydá soubor | ✅ | `AccountingExportTest` |
| 9 — nad stropem validační chyba, vidět na obrazovce | ✅ | `AccountingExportTest`, `AccountingScreenTest` |
| 10 — `&` a `<script>` v názvu produktu, výstup je platné XML | ✅ | oba formátové testy |
| 11 — dávka nikdy neobsahuje doklad jiného nájemce | ✅ | `AccountingIsolationTest` |
| 12 — každý export do `AuditLog` | ✅ | `AccountingExportTest` |

## Testy

10 souborů, ~60 testů modulu; celá sada **1734 zelených**. Unit: `DocumentAmounts` (nula, haléř, záporná částka), `VatRateMap` (přesné sazby + odmítnutí), `IsdocUuid` (stabilita a rozlišení per tenant/typ), registry. Feature: autorizace, prázdné období, strop, obsah obou formátů včetně hodnotových asercí, ZIP a názvy souborů, escapování, tenant izolace, audit včetně „po selhání žádný řádek", temp soubor po selhání.

Golden files (`tests/Fixtures/accounting/`) hlídají **drift struktury**, ne správnost formátu.

## Odchylky od specifikace

1. **Formátové writery čtou model `Modules\Docs\Models\Document` přímo**, ne přes kontrakt — porušuje rozhodnutí z 2026-07-22 („cizí modul nikdy nesahá na model `Document`"). Odhaleno při review, **vlastník rozhodl 2026-07-30 ponechat** a zapsat jako vědomou odchylku. Cena: `DocumentView` by musel vystavit celý snímek; přejmenování v `docs` se tu projeví tiše prázdnými poli (`?? []`), ne pádem.
2. **Přibyl `DocumentLines` a dopočtený řádek „Doprava a poplatky"** — spec s ním nepočítala, protože nepředpokládala, že snímek dopravu nenese.
3. **`isdoc()` má `type` povinný** — spec to říkala v próze, plán to měl jako volitelné s defaultem; sjednoceno na povinné.
4. **Ruční ověření na demu neproběhlo** (plán Task 10 krok 2).

## Technický dluh

> **Body 1 a 2 vyřešeny vlnou 2.12** — viz [`2026-07-31-doprava-na-dokladu.md`](2026-07-31-doprava-na-dokladu.md).

1. ~~**Snímek dokladu nenese dopravu ani poplatek za platbu**~~ (vyřešeno 2.12) (`InvoiceSnapshot`). Modul si to dopočítá, ale **zákaznické PDF faktury má tutéž díru** — řádky se nesečtou na „Celkem k úhradě". Patří do `Modules/Docs`, ne sem, a mělo by se opravit dřív než pozdě, protože jde o doklad, který zákazník dostává dnes.
2. **Sazba chybějící ve `vat_summary`** (neplátce DPH, nebo doprava/platba bez `tax_rate_id`) nedostane dopočtený řádek, ale `PayableAmount` se pořád bere z `documents.total` — ISDOC si pak může sám odporovat. Netestováno.
3. **Korekce zaokrouhlení upravuje `line_net`, ne `unit_net`** — u řádku s množstvím 1 může být `UnitPrice` o haléř jinde než `LineExtensionAmount`.
4. **Chybí testy** na „když řádky sedí, žádný dopočtený řádek nevznikne" a na doklad s víc sazbami.
5. **Sleva na položce**: Pohoda si řádek dopočítá z množství × jednotková cena, což se u zlevněné položky rozejde s `line_total`. Řešení (`inv:discountPercentage`, nebo `typ:price`/`priceSum`) není offline ověřitelné.
6. Golden files porovnávají strukturu; hodnotové aserce existují, ale jen pro jeden doklad.

## Pre-deploy checklist

- [ ] **Reálný import Pohoda XML do Pohody** — tvary elementů i znaménko dobropisu jsou z veřejné dokumentace, ne z XSD. Znaménko je zapouzdřené v jedné metodě per formát, takže korekce je zásah na jednom místě.
- [ ] **Validace ISDOC proti oficiálnímu XSD 6.0.1.**
- [ ] Ověřit export u **neplátce DPH** (viz dluh 2) a u dokladu s víc sazbami.
- [ ] `php artisan modules:sync` před `migrate` (nový modul se sám přiřadí premium tarifům přes `PlanModuleDefaults`), pak `npm run build`.
- [ ] Ověřit, že si nájemce na premium tarifu modul opravdu zapne (poučení z vlny 2.9).
