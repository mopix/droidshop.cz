# Fáze 1, vlna 1.6 — Dobropis, proforma, CSV VAT export, číslování (`docs`)

**Datum:** 2026-07-22
**Status:** approved (brainstorming)
**Související plán:** `docs/superpowers/plans/2026-07-22-faze-1-vlna-16-docs.md` (vznikne po schválení specu)
**Zdroj pravdy:** produktová specifikace §16.6 (modul `docs`, base — enum `type` = invoice/proforma/credit_note), §15.1 (`SequenceService`, gap-free číslování), §29 zákona o DPH (náležitosti opravného daňového dokladu — provozní ověření), navazuje na as-is `docs/as-is/2026-07-22-docs.md`

## Kontext

Vlna 1.5 dodala **jádro faktur**: typ `invoice` se vystaví ručně i automaticky, vygeneruje PDF, pošle zákazníkovi, uloží na privátní disk, stáhne v adminu i účtu. Schéma `documents` a enum `type` (`invoice`/`proforma`/`credit_note`) stojí kompletní od začátku — dobropis a proforma se v 1.5 jen nevystavovaly. `InvoiceIssuer::issue()` už bere `$type` jako parametr, ale jediný volající posílá vždy `invoice`.

Tato vlna doplní zbývající dva typy dokladů, účetní CSV export a dořeší formát číslování, který 1.5 odložila (komentář `Modules/Docs/Services/InvoiceIssuer.php:70`: „zero-padding a yearly {YYYY}{NNNN} reset jsou wave 1.6").

Infrastruktura z 1.5 stojí připravená a tato vlna ji rozšiřuje, nepřepisuje:

- `App\Core\Sequences\SequenceService` — gap-free čítač klíčovaný `(tenant_id, series)`, `next($series)` vrací `prefix.number`, `configure()` refreshuje prefix
- `Modules\Docs\Services\InvoiceIssuer` + `InvoiceSnapshot` — vzor snímku dokladu z `tenants.billing_*` a `OrderView`
- `Modules\Docs\Jobs\GenerateInvoicePdf` — tenant-aware render + uložení + e-mail
- `Modules\Docs\Mail\InvoiceIssued`, `Modules\Docs\Support\InvoiceQr`, PDF šablona `pdf/invoice.blade.php`
- `App\Core\Documents\Contracts\{DocumentIssuer, DocumentBook, DocumentView}` + `Null*` guest-safe bindingy
- `Modules\Orders\Models\Order` — stavy `FULFILLMENT_CANCELLED`, `PAYMENT_REFUNDED`, `PAYMENT_PAID`; `OrderBook::findForAdmin`

## Rozsah vlny

**Uvnitř 1.6:**

1. **Číslování** — roční reset + zero-padding, samostatná číselná řada per typ dokladu.
2. **Dobropis (`credit_note`)** — plný storno-dobropis, ruční tlačítko v adminu.
3. **Proforma (`proforma`)** — nedaňová výzva k platbě, ruční tlačítko v adminu.
4. **CSV VAT export** — účetní export dokladů za období podle DUZP.

**Odloženo do `docs/future/` (mimo tuto vlnu):**

- Částečný dobropis (výběr položek/množství k vrácení) — MVP dělá jen plný storno-dobropis.
- Automatické vystavení proformy při objednávce s převodem — MVP jen ruční tlačítko.
- Provazba proforma ↔ faktura (odečet zálohy v ostré faktuře po zaplacení proformy) — v MVP jsou to nezávislé doklady na téže objednávce.

## Rozhodnutí této vlny

### Architektura = registry + issuer per typ

`DocumentIssuer::issue(string $orderUuid, string $type): DocumentView` (kontrakt už `$type` nese) **deleguje** přes nový `DocumentIssuerRegistry` na konkrétní issuer podle typu: `InvoiceIssuer` / `CreditNoteIssuer` / `ProformaIssuer`. Kopíruje precedent `PaymentGatewayRegistry` (rozhodnutí 2026-07-21) a zásadu malých jednotek s jedním účelem.

Sdílená mechanika — alokace čísla, immutable insert, idempotence přes `(order_id, type)` unique, dispatch PDF jobu, `UniqueConstraintViolationException` fallback — se vytáhne do `DocumentWriter`. Každý issuer drží **jen** pravidlo svého snímku, které se mezi typy liší podstatně:

- **invoice** — kladný daňový doklad, `taxable_at` = DUZP, plná VAT rekapitulace (beze změny oproti 1.5).
- **credit_note** — negace snímku původní faktury (položky, `vat_summary`, `total` záporně), odkaz na číslo originálu.
- **proforma** — nedaňová výzva, `taxable_at` = null (bez DUZP), `vat_summary` informativní, bez daňové distinkce.

`NullDocumentIssuer` zůstává beze změny — registry žije jen v modulu `docs`, guest / vypnutý modul dostane stejnou `DocumentIssuanceUnavailable`.

Alternativa (jeden issuer s `match($type)`) zamítnuta: tlustne třídu a míchá tři nezávislá právní pravidla do jednoho těla. Alternativa (abstract base + potomci) zamítnuta: dědičnost je tužší než kompozice přes `DocumentWriter`.

### Číslování — roční reset + zero-padding, řada per typ

Účetní standard: každý typ dokladu vlastní souvislou číselnou řadu, číslo `{PREFIX}{YYYY}{NNNN}` s ročním resetem čítače.

- **Řada per typ:** `SequenceService` už klíčuje `(tenant_id, series)`. Roční reset = **rok součástí series klíče**, tj. `next("invoices:2026")`. Nový rok = nový řádek `sequences`, čítač přirozeně začne od 1. Migrace není — `series` je string, mění se jen hodnota.
- **Formát:** nový core `App\Core\Documents\DocumentNumber` sestaví `{PREFIX}{YYYY}{NNNN}` — prefix z nastavení per typ, rok z `taxable_at` (u proformy bez DUZP z `issued_at`), pořadí zero-padované na šířku `documents.number_pad` (default 4). `SequenceService::next()` vrací syrové pořadí; formátování se přesouvá z něj do `DocumentNumber`, aby čítač neznal prezentaci.
- **Config `config/documents.php`:** `credit_note_series`, `proforma_series` vedle `invoice_series`; `number_pad` (default 4).
- **Settings `docs`:** prefix per typ — `number_prefix` (invoice, zpětně kompat.), `credit_note_prefix`, `proforma_prefix`.

**Sémantická změna k zápisu do CLAUDE.md:** series klíč `SequenceService` nově nese rok — čítač se každý rok resetuje. Bez tohoto by číslo v roce 2027 pokračovalo od hodnoty 2026, což účetní nechce.

### Dobropis — plný storno-dobropis, ruční, gated

- **Spouštěč:** ruční tlačítko „Vystavit dobropis" v detailu objednávky. Žádný automat.
- **Gate:** objednávka má vystavenou fakturu (`DocumentBook::forOrder` obsahuje `invoice`) **a** je `FULFILLMENT_CANCELLED` **nebo** `PAYMENT_REFUNDED`. Jinak tlačítko skryté a `CreditNoteIssuer` vyhodí doménovou výjimku (`CreditNoteNotAllowed`) → 422. Právně dobropis opravuje **vystavený** doklad — bez originální faktury nelze.
- **Snímek:** negace snímku původní faktury. `items`/`vat_summary`/`total` záporně, `supplier`/`customer` beze změny, nové pole `corrects_number` = číslo originální faktury (a `corrects_document_id` pro provazbu). `taxable_at` = datum vystavení dobropisu (DUZP opravy).
- **Idempotence:** unique `(tenant_id, order_id, type)` → jeden `credit_note` na objednávku (plný storno = plné vrácení, bod 1). Opakovaný klik vrátí existující.
- **PDF `pdf/credit-note.blade.php`:** titul „Opravný daňový doklad – dobropis", odkaz na číslo a datum původní faktury, záporné částky, **bez QR** (nevrací se platba QR kódem).
- **Doručení:** e-mail zákazníkovi + admin download/resend + stažení v účtu zákazníka — reuse zobecněné infrastruktury.

### Proforma — nedaňová výzva, ruční

- **Spouštěč:** ruční tlačítko „Vystavit proformu" v detailu objednávky. MVP bez automatu.
- **Vhodné pro:** neuhrazenou objednávku (typicky bankovní převod) — zákazník dostane výzvu k platbě.
- **Nedaňový doklad:** `taxable_at` = **null** (proforma nemá DUZP), patička/hlavička „Není daňový doklad", `vat_summary` informativní (rozpad se tiskne, ale doklad není podkladem pro odpočet). `due_at` = splatnost.
- **Řada `proformas`, prefix `proforma_prefix`.** Unique `(tenant, order_id, proforma)` → jedna proforma na objednávku. Nezávislá na faktuře — obě koexistují (unique je per typ).
- **QR:** pro převod stejně jako nezaplacená faktura (`InvoiceQr` zobecnit na `DocumentQr`).
- **PDF `pdf/proforma.blade.php`.**

### CSV VAT export — podle DUZP, invoice + credit_note

- **Obrazovka:** admin sekce Doklady, formulář rozsah `od`/`do` (datum) → download CSV.
- **Data:** nový read kontrakt `App\Core\Documents\Contracts\DocumentLedger` + `EloquentDocumentLedger` v modulu. Dotaz: `type IN (invoice, credit_note)`, `taxable_at` v `[od, do]`, tenant-scoped. **Proforma vyloučena** (není daňový doklad). Dobropis se záporným základem/DPH/celkem.
- **DUZP, ne datum vystavení:** účetní potřebuje `taxable_at` pro přiznání DPH.
- **Sloupce:** `cislo`, `typ`, `vystaveno`, `duzp`, `odberatel`, `ico`, `dic`, `zaklad_zakladni`, `dph_zakladni`, `zaklad_snizena`, `dph_snizena`, `celkem`, `mena`. Rozpad per sazba z `vat_summary` snímku. (Přesný seznam sazeb potvrdit v plánu proti tvaru `vat_summary`.)
- **Streamování:** `StreamedResponse` — velký rozsah nesmí sežrat paměť. UTF-8 BOM kvůli Excelu, oddělovač `;` (české locale Excelu).
- **Přístup:** permission `docs.manage`, hlavička `X-Robots-Tag: noindex`.

### Zobecnění infrastruktury 1.5

- `GenerateInvoicePdf` → `GenerateDocumentPdf` — typ vybírá blade šablonu (`invoice`/`credit-note`/`proforma`). Zpětně kompatibilní: dispatch z registry.
- `InvoiceIssued` mail → `DocumentIssued` — titul a text dle typu.
- `InvoiceQr` → `DocumentQr` — QR jen pro doklady čekající platbu (nezaplacená faktura, proforma).
- `Document` immutabilita (`booted()` hook: update jen `pdf_path`/`sent_at`, delete vždy vyhodí) platí pro všechny typy beze změny.

## Akceptační kritéria

1. **Dobropis** jde vystavit tlačítkem u stornované/refundované objednávky s fakturou; u objednávky bez faktury nebo nestornované je akce odmítnuta (422, tlačítko skryté).
2. Dobropis nese **záporné** částky, odkaz na číslo původní faktury, vlastní číselnou řadu a PDF bez QR.
3. **Proforma** jde vystavit tlačítkem u neuhrazené objednávky; je nedaňový doklad (`taxable_at` null, patička „Není daňový doklad"), má QR pro převod, vlastní řadu.
4. Faktura i proforma **koexistují** na jedné objednávce (unique je per typ).
5. **Číslo dokladu** má tvar `{PREFIX}{YYYY}{NNNN}` (zero-pad), čítač se **resetuje s rokem**, souběh nevyrobí díru ani duplicitu.
6. **CSV export** vrátí doklady typu invoice+credit_note s DUZP v zadaném rozsahu, dobropis záporně, proforma vyloučena; soubor je validní UTF-8 s BOM, otevře se v Excelu.
7. **Tenant izolace:** dobropis, proforma i CSV export vidí a míchají jen doklady vlastního tenanta.
8. **Immutabilita:** dobropis i proforma jsou po vystavení neměnné (jen `pdf_path`/`sent_at`).
9. **Guest-safe:** vypnutý modul `docs` → issue všech typů i CSV export vyhodí `DocumentIssuanceUnavailable` / nedostupné, nákupní tok se nerozbije.
10. Celá test suite zelená (858+ na začátku vlny).
11. **Náležitosti opravného daňového dokladu podle §29 zákona o DPH** ověřit s účetní/právníkem — provozní krok, do pre-deploy checklistu, ne blokér kódu.

## Testy

Nové sady v `tests/Feature/Modules/Docs/`:

- `DocumentNumberTest` — formát `{PREFIX}{YYYY}{NNNN}`, zero-pad, roční reset (rok v series klíči), souběh bez díry (unit + feature nad `SequenceService`).
- `CreditNoteIssuerTest` — snímek negovaný, `corrects_number` = originál, vlastní řada, idempotence, souběh.
- `CreditNoteGateTest` — bez faktury odmítnuto, nestornovaná/neuhrazená odmítnuta, cancelled i refunded povoleny.
- `ProformaIssuerTest` — `taxable_at` null, vlastní řada, koexistence s fakturou, QR přítomen.
- `VatExportTest` — rozsah DUZP, invoice+credit_note, dobropis záporně, proforma vyloučena, tenant izolace, UTF-8 BOM/oddělovač, streamovaný obsah.

Rozšířit: `DocumentAdminTest` (nové akce a gaty), `GenerateInvoicePdfTest` → pokrýt zobecněný `GenerateDocumentPdf` pro tři typy, `DocsModuleManifestTest` (nové permissions/nav pokud přibydou).

## Mimo rozsah

- Částečný dobropis, auto-proforma, provazba proforma↔faktura → `docs/future/2026-07-22-docs-1-6-odlozene.md`.
- Vizuální ladění PDF (font, diakritika) — přetrvává z 1.5 do pre-deploy checklistu.
- Logo tenanta v PDF — přetrvávající kosmetický dluh z 1.5.
- ISDOC / XML export, EET — mimo MVP.
