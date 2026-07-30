# Vlna 2.11 — Modul `accounting`: export dokladů do Pohody a ISDOC (premium) — design

Datum: 2026-07-30 · Fáze 2 · Navazuje na: `docs` (immutable snímek dokladu, kontrakt `DocumentLedger`, CSV VAT export z vlny 1.6), nastavení modulů a `PlanModuleDefaults` (vlna 2.10 a navazující oprava tarifů)

**Status:** approved

## Cíl

Nájemcova účetní dnes dostane z e-shopu jedno CSV se souhrnem DPH za období. Do účetního programu se z něj doklady nedostanou — musí je opsat. Modul `accounting` vydá tytéž doklady ve formátech, které účetní software přímo importuje: **Pohoda XML** (dávka za období) a **ISDOC 6.0.1** (otevřený český standard, umí ho i Money, ABRA, iDoklad).

Je to zároveň **první nový premium modul**. Premium tarif dnes odlišuje jen `discounts` a limity; specifikace §16 označuje „ISDOC/Pohoda XML" za premium `accounting` a kap. 14 ho drží v premium seznamu.

Modul nic nepočítá. Doklad je immutable snímek (vlna 1.5), takže export je čistě mapování už hotových dat do dvou XML tvarů — proto se dá postavit malou vlnou.

## Mimo rozsah (→ `docs/future/accounting-dalsi-kroky.md`)

- **ISDOC jako příloha k faktuře pro odběratele** — přiložit `.isdoc` k `DocumentIssued` e-mailu a do stahování v účtu zákazníka. Vědomě odloženo: sahá na transakční poštu a zákaznickou plochu, kdežto tahle vlna slouží účetní nájemce.
- **Money S3 a další proprietární formáty** — každý je vlastní XSD, číselníky a testy; bez nájemce, který ho žádá, je to slepá investice. Registry formátů je na přidání připravené.
- **Filtry exportu** (jen typ dokladu, jen konkrétní odběratel) a zejména **„jen nezaúčtované"** — to poslední znamená držet stav zaúčtování, tedy novou evidenci a jinou funkci, ne filtr.
- **Plná mapovací obrazovka** (párování sazeb DPH, číselných řad, předkontací per položka) — vlastní tabulky a UI.
- **Fronta a historie běhů exportu** — vlna streamuje v requestu se stropem, viz Rozhodnutí 4.

## Role a viditelnost

| Kdo | Co smí |
|-----|--------|
| `TENANT_ADMIN` (owner) | vidí obrazovku, exportuje, nastavuje předkontace |
| `TENANT_STAFF` | jen s právem `accounting.export` (nedostane ho automaticky) |
| `CUSTOMER` | nic — modul nemá veřejnou plochu |
| `SUPERADMIN` | nic navíc; přiřazení modulu tarifu řeší `/superadmin/tarify` |

Účetní podklady jsou citlivější než třeba výdej zásilek: obsahují úplný seznam odběratelů, jejich IČO/DIČ a obraty. Právo je proto samostatné, ne svezené pod `docs.manage`.

## Rozhodnutí z brainstormingu (závazná)

1. **Adresátem je účetní nájemce, ne odběratel.** Jednosměrný export z adminu. ISDOC příloha k faktuře pro odběratele je mimo rozsah.
2. **Dva formáty: Pohoda XML a ISDOC.** Registry formátů, takže třetí je nový soubor bez zásahu do stávajících.
3. **Dávka za období + jednotlivý doklad.** Období podle **DUZP** a jen daňové doklady (`invoice`, `credit_note`) — táž politika, jakou má CSV VAT export z vlny 1.6. Dvě exportní cesty ze stejných dat nesmí tvrdit každá něco jiného.
4. **Streamovat v requestu se stropem, ne frontu.** `config('accounting.max_documents')` = 5 000; nad tím validační chyba s instrukcí zvolit kratší období. Účetní export je měsíční rutina, ne noční dávka; fronta by přidala tabulku běhů, stavovou obrazovku a `failed()` hook kvůli případu, který nastane málokdy. Strop je poctivější než tichý timeout.
5. **Vlastní obrazovka `/admin/m/accounting` a vlastní právo `accounting.export`.** Modul musí jít vypnout, aniž po sobě něco nechá; blok uvnitř obrazovky modulu `docs` by z něj udělal závislost na cizím UI.
6. **Konfigurace Pohody přes schéma nastavení z vlny 2.10.** Prázdná hodnota = element se do XML nevloží (degradace na výchozí předkontace v Pohodě), ne pád.
7. **Sazby DPH se mapují tabulkou a neznámá sazba export odmítne.** `21 → high`, `12 → low`, `0 → none`. Pohoda má tři nenulové úrovně; tichý fallback by naimportoval nesprávnou daň. Stejný závěr jako povinné `tax_rate_id` ve vlně 2.7 — účetní číslo nemá vznikat z domněnky.
8. **Žádné `requires` na `docs`.** Deklarovaná závislost by z `docs` udělala nevypnutelný modul (precedent `checkout`/`orders`). Runtime gate: null binding `DocumentLedger` vrací prázdno a obrazovka řekne „žádné doklady k exportu".

## Modul

`Modules/Accounting/module.json`: `core: false`, `level: premium`, `permissions: ["accounting.export"]`, `settings_schema: "settings.json"`, `settings_permission: "accounting.export"`, `requires: {}`, nav `Účetní export` (area admin, order 57 — hned za Doklady).

Přiřazení tarifu **nepotřebuje migraci**: `PlanModuleDefaults` (2026-07-30) uděluje `level: premium` moduly premium tarifům podle manifestu, a `modules:sync` to udělá pro modul, který právě vytvořil.

### Routy

Modulová admin skupina (`module:accounting` → `tenant.member`), právo kontroluje controller.

| Metoda | Cesta | Jméno | Co |
|---|---|---|---|
| GET | `/admin/m/accounting` | `admin.accounting.index` | formulář období + volba formátu |
| GET | `/admin/m/accounting/export` | `admin.accounting.export` | stažení dávky |
| GET | `/admin/m/accounting/isdoc/{number}` | `admin.accounting.isdoc` | jeden doklad jako `.isdoc` |

Tlačítko „ISDOC" v seznamu dokladů (`/admin/m/docs`) se renderuje jen tehdy, když e-shop běží `accounting` (`ShopModules->has('accounting')`) a uživatel má právo — odkaz míří na routu modulu. Stejný vzor, jakým košík zobrazuje pole pro slevový kód jen s modulem `discounts`.

### Schéma nastavení

`Modules/Accounting/settings.json`, všechna pole `nullable|string|max:20`, typ `text`:

| Klíč | Label |
|---|---|
| `pohoda_predkontace_faktura` | Předkontace faktury (např. `3Fv`) |
| `pohoda_predkontace_dobropis` | Předkontace dobropisu (např. `3FvKr`) |
| `pohoda_cleneni_dph` | Členění DPH (např. `UD`) |
| `pohoda_stredisko` | Středisko |
| `pohoda_cinnost` | Činnost |

Nastavuje se na `/admin/nastaveni/moduly/accounting` — generická obrazovka z vlny 2.10, tedy nulový UI kód.

## Jádro

`DocumentLedger` dostane druhou metodu vedle stávajícího `taxableBetween()`:

```php
public function findTaxDocument(string $number): ?DocumentView;
```

Cizí modul nesmí sahat na model `Document` (rozhodnutí 2026-07-22), takže dohledání jednoho dokladu podle čísla musí projít kontraktem. Implementace **filtruje typ na `invoice` a `credit_note`**: unique je od vlny 1.6 `(tenant_id, type, number)`, takže proforma smí nést stejné číslo jako faktura, a bez filtru by tlačítko ISDOC vydalo nedaňový doklad vystupující jako daňový. `NullDocumentLedger` vrací `null`.

## Komponenty modulu

| Soubor | Role |
|---|---|
| `Contracts/AccountingFormat.php` | `key()`, `label()`, `filename()`, `mime()`, `write()` |
| `Support/AccountingFormats.php` | registry podle klíče (`pohoda`, `isdoc`); precedent `PaymentGatewayRegistry` |
| `Support/PohodaXmlFormat.php` | `dat:dataPack` dávka |
| `Support/IsdocFormat.php` | jeden `.isdoc`, dávka jako ZIP |
| `Support/DocumentAmounts.php` | haléře → desetinné číslo s **tečkou**, celočíselně (žádná float aritmetika nad penězi) |
| `Support/VatRateMap.php` | `21/12/0 → high/low/none`, jinak výjimka `UnsupportedVatRate` |
| `Exceptions/UnsupportedVatRate.php` | nese číslo dokladu i sazbu |
| `Http/Controllers/AccountingExportController.php` | `index`, `export`, `isdoc` |
| `Http/Requests/ExportDocumentsRequest.php` | období, `format` v `in:pohoda,isdoc`, strop počtu |
| `resources/js/Pages/Modules/Accounting/Index.vue` | formulář (jádrový strom, viz rozhodnutí 2026-07-20) |
| `config/accounting.php` | `max_documents` |

## Mapování

Zdrojem je **vždy snímek dokladu** (`documents.supplier`, `customer`, `items`, `vat_summary`, `total`), nikdy živá objednávka nebo produkt. Export za červenec proto vydá i za rok totéž.

**Pohoda XML** — `dat:dataPack` s `ico` ze snímku dodavatele, `dat:dataPackItem` per doklad, uvnitř `inv:invoice`:

- hlavička: `invoiceType` (`issuedInvoice` / `issuedCreditNotice`), `number/typ:numberRequested` = naše číslo, `date` = vystaveno, `dateTax` = DUZP, `dateDue` = splatnost, `symVar` = číslo dokladu, `partnerIdentity/typ:address` z `customer.billing` (firma, jméno, ulice, město, PSČ, IČO, DIČ),
- z nastavení: `accounting/typ:ids` (podle typu dokladu), `classificationVAT/typ:ids`, `centre`, `activity` — prázdné se **nevkládá**,
- položky z `items`: text, množství, `rateVAT`, jednotková cena bez DPH,
- souhrn z `vat_summary`: `priceHigh`/`priceHighVAT`, `priceLow`/`priceLowVAT`, `priceNone`.

**ISDOC 6.0.1** — `Invoice` s `DocumentType`, `ID` = číslo, `IssueDate`, `TaxPointDate`, `AccountingSupplierParty`, `AccountingCustomerParty`, `InvoiceLines`, `TaxTotal` po sazbách, `LegalMonetaryTotal`. `UUID` se generuje **deterministicky** (UUID v5 z `tenant_id` + typ + číslo): importní nástroje na něm dedupují, takže náhodné UUID by z opakovaného exportu jednoho dokladu udělalo dva doklady.

**XML skládá `XMLWriter`, nikdy konkatenace.** Do dokladu jdou názvy produktů a adresy psané nájemcem i zákazníkem — escapování patří knihovně. Tatáž třída problému jako CSV formula injection ve VAT exportu (vlna 1.6).

**Doručení:** Pohoda XML streamovaně (`StreamedResponse`: hlavička → doklady → patička). ISDOC dávka do temp ZIPu (`ZipArchive`) odeslaného s `deleteFileAfterSend` — nespotřebovává úložiště nájemce ani nepadá do limitu `storage_mb`. Jméno souboru v archivu nese **typ i číslo** (`faktura-2026001.isdoc`, `dobropis-2026001.isdoc`): při prázdných prefixech řad může faktura a dobropis nést stejné číslo a jeden by v archivu ten druhý přepsal.

## Chyby a hraniční stavy

| Stav | Chování | Proč |
|---|---|---|
| Období bez dokladů | redirect s hláškou, **žádný soubor** | prázdný soubor u účetní vypadá jako „nic se neprodalo", ne jako špatné období |
| Nad stropem | validační chyba u pole období | poctivější než timeout |
| Neznámá sazba DPH | 422, hláška **jmenuje číslo dokladu** | účetní číslo nemá vznikat z domněnky |
| Nevyplněné nastavení Pohody | element se nevloží, export projde | degradace, ne pád |
| Neznámý klíč formátu | validace `in:pohoda,isdoc` | |
| Cizí číslo dokladu | 404 (ledger je tenant-scoped) | čí doklady existují, není cizí věc |
| Vypnutý `docs` | prázdný ledger → hláška „žádné doklady" | null binding, ne 500 |
| `suspended` nájemce | export projde (GET) | §6.0 dává právo si data odvézt i po pozastavení |

**Audit:** `accounting.exported` s obdobím, formátem a počtem dokladů. Účetní data odcházejí z e-shopu ven; kdo je a kdy vytáhl, má být dohledatelné.

## Akceptační kritéria

1. Nájemce s `accounting.export` zadá období, zvolí Pohoda XML a stáhne soubor, který obsahuje jeden `dataPackItem` per daňový doklad s DUZP v období.
2. Tentýž nájemce zvolí ISDOC a stáhne ZIP s jedním `.isdoc` per doklad; jméno souboru nese typ i číslo.
3. Tlačítko u konkrétní faktury vydá jeden `.isdoc`; cizí číslo vrátí 404 a proforma se stejným číslem se nikdy nevydá.
4. Bez práva `accounting.export` je obrazovka 403; s vypnutým modulem 404.
5. Dobropis je v Pohoda XML `issuedCreditNotice` a v ISDOC opravný doklad; proforma není v žádném exportu.
6. Vyplněná předkontace a členění DPH se objeví v XML; nevyplněná znamená, že element chybí a export přesto projde.
7. Doklad se sazbou mimo 21/12/0 export odmítne a hláška uvede číslo dokladu.
8. Období bez dokladů nevydá soubor, ale hlášku.
9. Období nad stropem vrátí validační chybu s instrukcí zvolit kratší úsek.
10. Produkt s názvem obsahujícím `&` a `<script>` projde a výstup je platné XML.
11. Dávka nikdy neobsahuje doklad jiného nájemce.
12. Každý export zapíše `accounting.exported` do `AuditLog`.

## Testy

**Unit** — `DocumentAmounts` (haléře na desetinné číslo, nula, záporná částka dobropisu); `VatRateMap` včetně odmítnutí neznámé sazby; deterministické UUID (dvakrát totéž = stejné, jiný tenant se stejným číslem = jiné).

**Feature** — autorizace (403/404), prázdné období, strop, obsah Pohoda XML s nastavením i bez něj, typ dobropisu, vynechání proformy, ZIP s typem v názvu, jednotlivá routa, escapování, tenant izolace, audit.

**Golden file** — jeden fixture doklad proti uloženému očekávanému výstupu pro oba formáty. Zachytí nezáměrné přejmenování elementu nebo změnu pořadí; bez něj se formátová regrese pozná až u účetní.

## Rizika a technický dluh

**Tvary formátů nejsou ověřené proti oficiálním XSD.** Vycházejí z veřejné dokumentace; offline je nemám čím validovat. Do pre-deploy checklistu proto patří **reálný import do Pohody** a **validace proti ISDOC XSD**, včetně znaménkové konvence u dobropisu (náš snímek už je negovaný). Návrh to izoluje: znaménko a formátování peněz řeší `DocumentAmounts` a per-formát metoda, takže korekce je zásah na jednom místě. Golden file hlídá, že se výstup nezmění nezáměrně — **ne** že je od začátku správný.

**Strop 5 000 dokladů** je odhad, ne měření. Až bude nájemce s velkým obratem, změří se skutečná doba generování a strop se buď zvedne, nebo se doplní fronta (viz Mimo rozsah).

**Předkontace je jedna pro celý doklad**, ne per položka. Nájemce, který potřebuje různé předkontace podle druhu zboží, si bude muset doklady v Pohodě dorovnat — plná mapovací obrazovka je mimo rozsah.

## Reference

- Produktová spec: kap. 14 (rozdělení base/premium), §16 modul `docs` („ISDOC/Pohoda XML = premium `accounting`")
- `docs/as-is/2026-07-22-docs-1-6.md` — snímek dokladu, `DocumentLedger`, CSV VAT export
- `docs/as-is/2026-07-29-nastaveni-modulu.md` — schéma nastavení modulu, `PlanModuleDefaults`
- `docs/future/premium-moduly-dalsi-kandidati.md` — `abandoned-cart`, `licensing`
