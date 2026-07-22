# Fáze 1, vlna 1.5 — Doklady k objednávkám (`docs`)

**Datum:** 2026-07-21
**Status:** done
**Související plán:** `docs/superpowers/plans/2026-07-21-faze-1-vlna-15-docs.md` (vznikne po schválení)
**Zdroj pravdy:** produktová specifikace §16.6 (modul `docs`, base), §16.4 (tlačítko „vytvořit doklad" v detailu objednávky), §15.1 (`SequenceService`), pozn. §16 bod 8 (zaokrouhlování DPH v haléřích)

## Kontext

Vlny 1.3 a 1.4 uzavřely nákupní tok od katalogu po zaplacení kartou. Objednávka existuje, přejde do `paid`, ale zákazník ani nájemce nedostanou **fakturu / prodejní doklad**. Bez dokladu nelze v ČR reálně prodávat (spec §16.6 označuje `docs` jako **base** modul — konkurence to má v základu).

Tato vlna dodá **jádro faktur**: vystavení faktury k objednávce (ruční tlačítko + automaticky při zaplacení), PDF (A4, logo, QR), immutable snapshot, gap-free číslování, e-mail zákazníkovi, odkaz v jeho účtu a v adminu.

Infrastruktura z dřívějších vln stojí připravená a tato vlna ji jen oživuje:

- `App\Core\Sequences\SequenceService::next('invoices')` — gap-free číslování, `configure()` pro prefix/formát
- `tenants.billing_name/ico/dic`, `tenants.billing_address` (JSON), `tenants.vat_payer` (bool) — kompletní data dodavatele, **netřeba nové sloupce**
- `FileStorage` — abstrakce nad lokálním diskem (přechod na S3 = změna configu)
- `MailService` + `MailKind::Transactional` — odeslání PDF e-mailem, limit tarifu transakční poštu neblokuje
- `endroid/qr-code` — SPAYD QR platba už v repu
- `OrderView` / `OrderSettlement` kontrakt — snapshot objednávky; `OrderWorkflow::transitionPayment` jako choke point pro auto-vystavení

## Rozhodnutí této vlny

### Rozsah = jádro faktur

Uvnitř 1.5: vystavení faktury (typ `invoice`) ručně i automaticky, PDF, e-mail, účet zákazníka + admin.

**Odloženo do vlny 1.6:** dobropis (`credit_note`) při stornu zaplacené objednávky, CSV VAT export za období, proforma (`proforma`), částečné dobropisy. Enum `type` nese všechny tři hodnoty od začátku (immutabilita schématu), v 1.5 se vystavuje jen `invoice`.

### Modul `docs`, kontrakt `DocumentIssuer`

Nový nwidart modul `Modules/Docs`, manifest klíč `docs`, **base** (nelze vypnout, spec §16.6). Cizí moduly nikdy nesahají na Eloquent model dokladu — komunikace přes kontrakty v `app/Core/Documents/`:

- `DocumentIssuer` — `issue(string $orderUuid, string $type = 'invoice'): DocumentView`. **Idempotentní**: existující doklad daného typu k objednávce vrátí beze změny, číslo ze sekvence nespotřebuje.
- `NullDocumentIssuer` — guest-safe null binding (modul vypnut → no-op), stejný precedent jako `NullOrderPlacement`/`NullOrderSettlement`.
- `DocumentView` — úzký snapshot tvar (číslo, typ, PDF cesta, total, currency, issued_at, sent_at…), model `Document` ho implementuje. Stejný vzor jako `OrderView` a `CatalogProduct`.

### Auto-vystavení přes doménový event, ne saháním payments → docs

`OrderWorkflow::transitionPayment` (a `transitionFulfillment`) vystřelí doménový event **jen když přechod reálně proběhl** — což řeší idempotenci (duplicitní webhook + return = jeden event) na existujícím choke pointu. `docs` na event naslouchá a podle nastavení vystaví. Payments ani orders nezná modul `docs`. Kdyby settlement volal issuer přímo, provázal by moduly a obešel kill switch.

### dompdf, ne mpdf/Browsershot

`barryvdh/laravel-dompdf` (nová composer závislost, doinstaluje se v implementaci se souhlasem). Pure-PHP, žádná systémová binárka — sedí k local-first VPS. Slabší CSS (bez flex/grid) na fakturu A4 nevadí (tabulkový layout). Browsershot zamítnut (vyžaduje Chromium na serveru, těžký na queue worker); mpdf zamítnut (těžší, bez Laravel wrapperu) — dompdf stačí a je nejznámější.

### Neplátce DPH = render distinkce, ne nový typ

Plátce → titul „Faktura – daňový doklad", DIČ, DPH rekapitulace. Neplátce → titul „Faktura", bez DIČ, `vat_summary` nulové. Rozdíl je jen v renderu šablony a obsahu snapshotu, ne nová hodnota enumu.

## Cíle

- [ ] Nájemce vystaví fakturu k objednávce tlačítkem v detailu objednávky (admin)
- [ ] Faktura se vystaví automaticky při zaplacení (`order.paid`), pokud to nastavení tenanta dovolí (default zapnuto)
- [ ] Doklad je immutable snapshot — po vystavení ho nelze editovat ani smazat (jen dobropisovat, 1.6)
- [ ] Číselná řada faktur je bez děr i při souběhu (`SequenceService`)
- [ ] PDF (A4, logo tenanta, QR u nezaplacených, patičkový text) se vygeneruje jobem a uloží přes `FileStorage`
- [ ] Zákazník dostane fakturu e-mailem a stáhne si ji v účtu (historie objednávek)
- [ ] Plátce vs neplátce DPH: správné náležitosti dokladu
- [ ] VAT rekapitulace počítaná v haléřích (integer), per-položka pořadí
- [ ] Vypnutý / nenainstalovaný modul (`NullDocumentIssuer`) nerozbije objednávkový tok

## Mimo rozsah

- Dobropis (`credit_note`) při stornu zaplacené objednávky — vlna 1.6
- CSV VAT export za období — vlna 1.6
- Proforma faktura — vlna 1.6
- Částečné dobropisy — fáze 2
- ISDOC / Pohoda XML export — premium modul `accounting`, fáze 3
- Úplné ověření náležitostí §29 ZDPH s účetní/právníkem — provozní krok před spuštěním, ne kód (AK odkazuje, spec §16.6)
- Fakturace platformy nájemcům (`billing`) — jiná doména, jiné příjmy

## Datový model

`documents` přesně dle spec §16.6:

```
documents(
  id, tenant_id, order_id,
  type ENUM('invoice','proforma','credit_note'),
  number, series,
  issued_at, taxable_at, due_at,
  supplier JSON, customer JSON, items JSON, vat_summary JSON,
  total, currency,
  pdf_path NULLABLE, sent_at NULLABLE,
  timestamps
)
```

- `BelongsToTenant` (globální scope, `tenant_id` ve všech dotazech)
- Unique `(tenant_id, number)` — číslo nesmí kolidovat uvnitř tenanta
- **Immutable**: žádný update mimo `pdf_path` a `sent_at`, žádné delete (vynuceno absencí edit/destroy cest + testem)
- Plný snapshot: `supplier` z `tenants.billing_*` + `vat_payer` v okamžiku vystavení, `customer`/`items`/`vat_summary`/`total` z objednávky — doklad se nemění, i když se pak změní produkt, cena nebo profil tenanta

### Nastavení tenanta (manifest schema modulu `docs`)

- `auto_issue_on` — `paid` | `shipped` | `manual`, default `paid`
- `email_invoice` — bool, default `true`
- `invoice_footer` — text (patička PDF)
- `due_days` — int, default `14` (splatnost, `taxable_at + due_days`)
- Prefix / formát číselné řady přes `SequenceService::configure('invoices', …)`

## Náležitosti dokladu (§16.6, §29 ZDPH — ověřit provozně)

Dodavatel (z nastavení tenanta), odběratel, DUZP (`taxable_at`), datum vystavení (`issued_at`) a splatnosti (`due_at`), položky se sazbami, rekapitulace DPH per sazba, způsob platby, VS (číslo objednávky), QR platba u nezaplacených. Plátce DPH → faktura – daňový doklad (DIČ, DPH); neplátce → doklad bez DPH náležitostí.

## Architektura (přehled)

- **`app/Core/Documents/`** — `Contracts\DocumentIssuer`, `Contracts\DocumentView`, `NullDocumentIssuer`. Případný doménový event pro auto-issue (`app/Core/Orders/Events/` nebo `Modules/Orders/Events/`).
- **`Modules/Docs/`** — `Models\Document` (implementuje `DocumentView`), `Services\InvoiceIssuer` (implementuje `DocumentIssuer`: čte `OrderView`, sestaví snapshot, alokuje číslo + insert v jedné transakci, dispatch PDF jobu), `Jobs\GenerateInvoicePdf` (tenant-aware, dompdf z Blade, `FileStorage` uloží, `pdf_path`), `Listeners\IssueInvoiceOnPaid` (naslouchá event, řídí se `auto_issue_on`), `Mail\InvoiceIssued`, `Http\Controllers` (admin: vystavit / seznam / stáhnout / poslat znovu; storefront: stažení zákazníkem), Blade PDF šablona.
- **Orders** — `OrderWorkflow::transitionPayment`/`transitionFulfillment` vystřelí doménový event jen při reálném přechodu.
- **Inertia stránky** — `resources/js/Pages/Modules/Docs/` (rozhodnutí 2026-07-20, view finder je uvnitř modulu nenajde).

## Role a viditelnost

| Role | Přístup |
|------|---------|
| `SUPERADMIN` | Nepřímo přes impersonaci tenanta; jinak se dokladů tenantů netýká |
| `TENANT_ADMIN` (`docs.manage`) | Vystavit doklad, seznam dokladů, stáhnout PDF, poslat znovu e-mailem |
| `TENANT_STAFF` | Post-MVP (permission plocha připravena) |
| `CUSTOMER` | Stáhnout **vlastní** fakturu v účtu (gate `auth:customer` + vlastník objednávky dle uuid); odkaz v e-mailu. Guest bez účtu → jen e-mail |
| Veřejnost / storefront | Žádný přístup — doklad není veřejný, stránka `noindex` |

Právo `docs.manage` se odvozuje z manifestu modulu (rozhodnutí 2026-07-20) — vypnutý modul právo nikomu nedá.

## Testy (PHPUnit)

- Gap-free číslování faktur pod souběhem (žádná díra ani duplicita)
- Immutabilita — pokus o update/delete dokladu selže / cesta neexistuje
- Tenant izolace — tenant A nevidí ani nestáhne doklad tenanta B
- Idempotence issueru — dvojí `order.paid` (webhook + return) = jeden doklad, jedno číslo
- Auto-issue listener respektuje `auto_issue_on` (`paid` vystaví, `manual` ne, `shipped` až při expedici)
- Plátce vs neplátce — správné náležitosti a `vat_summary`
- VAT rekapitulace v haléřích, per-položka pořadí
- PDF job vytvoří soubor a naplní `pdf_path`
- Zákaznický gate — cizí uuid = 403; guest bez účtu nemá přístup do účtu
- `NullDocumentIssuer` — vypnutý modul objednávkový tok nerozbije

## Rozhodnutí k zápisu do CLAUDE.md po dokončení

- Modul `docs` base, kontrakt `DocumentIssuer` (null binding), `DocumentView` snapshot tvar
- Auto-vystavení přes doménový event z `OrderWorkflow` (reálný přechod = idempotence), ne saháním payments → docs
- Doklad immutable, oprava jen dobropisem (1.6)
- dompdf (barryvdh/laravel-dompdf), ne mpdf/Browsershot — pure PHP, local-first
- Neplátce DPH = render distinkce, ne nový typ enumu
- Snapshot dodavatele z `tenants.billing_*` v okamžiku vystavení — doklad se pozdější změnou profilu tenanta nemění
