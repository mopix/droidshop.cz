# Vlna 2.12 — doprava a poplatek na dokladu + dotažení účetního exportu — implementační plán

> **Pro agentní workery:** POVINNÁ SUB-SKILL: použij `superpowers:subagent-driven-development` nebo `superpowers:executing-plans` a implementuj plán task po tasku. Kroky mají checkboxy (`- [ ]`).

**Goal:** Faktura, kterou dostane zákazník, musí mít součet řádků rovný částce „Celkem k úhradě" — dnes na ní chybí doprava i poplatek za platbu.

**Architecture:** `InvoiceSnapshot` přidá do `documents.items` dva řádky ze snímků objednávky (`orderShippingSnapshot()`, `orderPaymentSnapshot()`) ve stejném tvaru, jaký položky už mají. Schéma tabulky, šablona PDF ani modul `accounting` se nemění. Vedle toho se dotáhnou dva otevřené body z vlny 2.11: konzistence ISDOC u neplátce DPH a automatická validace ISDOC proti oficiálnímu XSD.

**Tech Stack:** Laravel 13, PHP 8.3, `libxml` (vestavěné, `DOMDocument::schemaValidate`), PHPUnit.

Vychází z: [`docs/as-is/2026-07-30-accounting-export.md`](../../as-is/2026-07-30-accounting-export.md) — sekce Technický dluh, body 1 a 2.

## Proč to není kosmetika

Doklad je právní dokument. Dnes vytiskne položky za 1 998 Kč a pod nimi „Celkem k úhradě: 2 097 Kč", aniž kdekoli uvede, odkud těch 99 Kč je. Rozdíl je v DPH rekapitulaci, ale ta je po sazbách, ne po druhu plnění, takže zákazník ani jeho účetní nemá z dokladu jak zjistit, co platí. Modul `accounting` z vlny 2.11 si dopravu dopočítává právě proto, že ve snímku není.

## Rozhodnutí vlastníka (2026-07-31)

1. **Nulový řádek se zobrazuje.** Zvolená doprava je na dokladu vidět i za 0 Kč — zákazník má vědět, co si vybral, a „proč tam není doprava" je zbytečná reklamace.
2. **Historické doklady se nemění.** Vystavený doklad je immutable snímek a PDF už odešla. Oprava platí od dalšího vystavení; modul `accounting` si u starých dokladů dál dopočítá řádek „Doprava a poplatky", takže export sedí i u nich.
3. **ISDOC XSD se stáhne do repozitáře** a validace se stane testem, ne položkou v pre-deploy checklistu. Reálný import do Pohody zůstává na vlastníkovi (potřebuje licenci).

## Globální omezení

- **Žádná nová composer ani npm závislost.** `DOMDocument::schemaValidate()` je součást `ext-dom`/`libxml`.
- PHP 8.3, ne 8.4 featury.
- Kód, komentáře a commity **anglicky**; texty pro uživatele **česky s diakritikou**.
- **Vystavený doklad zůstává immutable** — žádná migrace nepřepisuje `documents.items` (rozhodnutí 2026-07-22 i bod 2 výše).
- Peníze jsou celá čísla v haléřích; žádná float aritmetika.
- Před commitem `./vendor/bin/pint` na dotčené soubory.
- Testy **jen ve foregroundu**, jeden příkaz po druhém (sdílená MySQL test DB); plná sada jen jako gate před mergem.

## Mapa souborů

| Soubor | Odpovědnost |
|---|---|
| `Modules/Docs/Services/InvoiceSnapshot.php` | + řádky dopravy a poplatku za platbu |
| `Modules/Docs/Services/ProformaSnapshot.php` | totéž, pokud staví `items` vlastní cestou (ověřit) |
| `Modules/Accounting/Support/DocumentLines.php` | konzistence ISDOC u chybějící sazby |
| `Modules/Accounting/Support/IsdocFormat.php` | dtto, souhrnné částky |
| `tests/Fixtures/isdoc/isdoc-invoice-6.0.1.xsd` | oficiální schéma (+ `README.md` s původem) |
| `tests/Feature/Modules/Docs/InvoiceSnapshotTest.php` | řádky na dokladu, nulové částky, dobropis |
| `tests/Feature/Modules/Accounting/IsdocSchemaTest.php` | validace výstupu proti XSD |
| `tests/Fixtures/accounting/*.xml` | regenerovat (přibyly řádky) |

---

### Task 1: Doprava a poplatek jako řádky dokladu

**Soubory:**
- Upravit: `Modules/Docs/Services/InvoiceSnapshot.php`
- Ověřit a případně upravit: `Modules/Docs/Services/ProformaSnapshot.php`
- Test: `tests/Feature/Modules/Docs/InvoiceSnapshotTest.php` (vytvořit)

**Rozhraní:**
- Konzumuje: `OrderView::orderShippingSnapshot(): ?array` (`id`, `name`, `price`, `charged`, `tax_rate_id`, `currency`), `OrderView::orderPaymentSnapshot(): ?array` (`id`, `name`, `fee`, `tax_rate_id`, `currency`), `App\Core\Tax\TaxRates::findById(int $id): TaxRate`.
- Produkuje: `documents.items` nově obsahuje až dva další řádky ve **stejném tvaru** jako položky (`name`, `quantity`, `unit_price`, `tax_rate`, `line_total`). Tvar se nemění, takže PDF šablona ani modul `accounting` nepotřebují úpravu.

- [ ] **Krok 1: Napiš padající test**

```php
<?php

namespace Tests\Feature\Modules\Docs;

use App\Core\Documents\Contracts\DocumentIssuer;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * A document must be readable on its own: the lines it prints have to add up to
 * the amount it asks for. Until wave 2.12 shipping and the payment fee lived
 * only in the total and the VAT recap, so a customer's invoice showed 1 998 Kč
 * of lines under a 2 097 Kč total with nothing explaining the difference.
 */
class InvoiceSnapshotTest extends DocsTestCase
{
    private function issuedInvoice(): Document
    {
        app(DocumentIssuer::class)->issue($this->placePaidOrder(), Document::TYPE_INVOICE);

        return Document::query()->where('type', Document::TYPE_INVOICE)->latest('id')->firstOrFail();
    }

    public function test_the_lines_add_up_to_the_documents_total(): void
    {
        $invoice = $this->issuedInvoice();

        $lines = array_sum(array_map(
            static fn (array $item): int => (int) $item['line_total'],
            $invoice->items,
        ));

        $this->assertSame($invoice->total->amount, $lines, 'Součet řádků se musí rovnat částce k úhradě.');
    }

    public function test_shipping_and_the_payment_fee_are_named_lines(): void
    {
        $invoice = $this->issuedInvoice();
        $names = array_column($invoice->items, 'name');

        $this->assertTrue(
            collect($names)->contains(fn (string $n) => str_contains($n, 'Doprava')),
            'Doklad musí pojmenovat dopravu: '.implode(' | ', $names)
        );
        $this->assertTrue(
            collect($names)->contains(fn (string $n) => str_contains($n, 'Platba')),
            'Doklad musí pojmenovat způsob platby: '.implode(' | ', $names)
        );
    }

    public function test_a_zero_priced_shipping_still_gets_a_line(): void
    {
        // Owner's decision 2026-07-31: a chosen delivery is shown even at 0 Kč,
        // so the customer can see what they picked.
        $this->context->runAs($this->tenant, function (): void {
            \Modules\Shipping\Models\ShippingMethod::query()->update(['price' => 0]);
        });

        $invoice = $this->issuedInvoice();
        $shipping = collect($invoice->items)->first(fn (array $i) => str_contains($i['name'], 'Doprava'));

        $this->assertNotNull($shipping);
        $this->assertSame(0, (int) $shipping['line_total']);
    }

    public function test_the_shipping_line_carries_its_own_vat_rate(): void
    {
        $invoice = $this->issuedInvoice();
        $shipping = collect($invoice->items)->first(fn (array $i) => str_contains($i['name'], 'Doprava'));

        $this->assertNotNull($shipping);
        $this->assertNotSame('', (string) $shipping['tax_rate'], 'Sazba DPH dopravy musí být na řádku.');
    }

    public function test_a_credit_note_negates_the_new_lines_too(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        Order::query()->where('uuid', $uuid)->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_CREDIT_NOTE);

        $note = Document::query()->where('type', Document::TYPE_CREDIT_NOTE)->latest('id')->firstOrFail();
        $shipping = collect($note->items)->first(fn (array $i) => str_contains($i['name'], 'Doprava'));

        $this->assertNotNull($shipping);
        $this->assertLessThanOrEqual(0, (int) $shipping['line_total'], 'Dobropis musí mít i dopravu se záporným znaménkem.');
    }
}
```

- [ ] **Krok 2: Spusť test, ověř pád**

Spusť: `php artisan test --filter=InvoiceSnapshotTest`
Očekávej: FAIL — součet řádků je nižší než `total` a řádky „Doprava"/„Platba" neexistují.

- [ ] **Krok 3: Doplň řádky do `InvoiceSnapshot`**

V `for()` nahraď stávající `'items' => …` tímto:

```php
            'items' => [
                ...$order->orderItems()->map(fn ($item): array => [
                    'name' => (string) $item->name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => $item->unit_price->amount,
                    'tax_rate' => (string) $item->tax_rate,
                    'line_total' => $item->line_total->amount,
                ])->all(),
                ...$this->serviceLines($order),
            ],
```

A přidej metody:

```php
    /**
     * Shipping and the payment fee as ordinary document lines.
     *
     * They belong on the document for the simplest possible reason: a document
     * whose lines do not add up to the amount it asks for cannot be checked by
     * the person paying it. Until wave 2.12 they lived only in the total and in
     * the VAT recap, which is grouped by rate, not by what was actually sold.
     *
     * A zero charge still gets a line (owner's decision 2026-07-31): free
     * delivery is a thing the customer chose and expects to see named.
     *
     * @return list<array{name: string, quantity: int, unit_price: int, tax_rate: string, line_total: int}>
     */
    private function serviceLines(OrderView $order): array
    {
        $lines = [];

        $shipping = $order->orderShippingSnapshot();

        if ($shipping !== null) {
            $lines[] = $this->serviceLine(
                'Doprava — '.($shipping['name'] ?? ''),
                (int) ($shipping['charged'] ?? 0),
                $shipping['tax_rate_id'] ?? null,
            );
        }

        $payment = $order->orderPaymentSnapshot();

        if ($payment !== null) {
            $lines[] = $this->serviceLine(
                'Platba — '.($payment['name'] ?? ''),
                (int) ($payment['fee'] ?? 0),
                $payment['tax_rate_id'] ?? null,
            );
        }

        return $lines;
    }

    /**
     * @return array{name: string, quantity: int, unit_price: int, tax_rate: string, line_total: int}
     */
    private function serviceLine(string $name, int $amount, ?int $taxRateId): array
    {
        // A non-VAT payer's methods carry no rate at all (the rate became
        // mandatory for VAT payers only in wave 2.7), and 0 is the honest
        // answer for them — not a guessed default.
        $percent = $taxRateId !== null
            ? (string) $this->taxRates->findById($taxRateId)->percent()
            : '0';

        return [
            'name' => trim($name, ' —'),
            'quantity' => 1,
            'unit_price' => $amount,
            'tax_rate' => $percent,
            'line_total' => $amount,
        ];
    }
```

Do konstruktoru přidej `private readonly TaxRates $taxRates` a importy `App\Core\Orders\Contracts\OrderView`, `App\Core\Tax\TaxRates`.

- [ ] **Krok 4: Ověř proformu**

Otevři `Modules/Docs/Services/ProformaSnapshot.php`. Pokud staví `items` vlastní cestou, uprav ji stejně; pokud deleguje na `InvoiceSnapshot`, nedělej nic. Do reportu napiš, která z variant platí.

- [ ] **Krok 5: Spusť testy**

Spusť: `php artisan test --compact tests/Feature/Modules/Docs`
Očekávej: PASS. **Očekávej i pády v existujících testech**, které počítají položky dokladu nebo asertují na jejich počet — to jsou legitimní dopady změny, ne regrese. Uprav je tak, aby počítaly s novými řádky, a v reportu vyjmenuj, které to byly.

- [ ] **Krok 6: Commit**

```bash
./vendor/bin/pint Modules/Docs tests/Feature/Modules/Docs
git add Modules/Docs tests/Feature/Modules/Docs
git commit -m "fix(docs): put shipping and the payment fee on the document"
```

---

### Task 2: Účetní export po změně snímku

**Soubory:**
- Upravit: `Modules/Accounting/Support/DocumentLines.php`, `Modules/Accounting/Support/IsdocFormat.php`
- Test: `tests/Feature/Modules/Accounting/IsdocFormatTest.php`, `tests/Feature/Modules/Accounting/PohodaXmlFormatTest.php`
- Regenerovat: `tests/Fixtures/accounting/pohoda-invoice.xml`, `tests/Fixtures/accounting/isdoc-invoice.xml`

**Rozhraní:** beze změny navenek; mění se jen chování dopočtu.

- [ ] **Krok 1: Napiš padající testy**

```php
    public function test_a_document_that_already_carries_shipping_gets_no_synthesised_line(): void
    {
        // Since wave 2.12 the snapshot carries shipping itself, so the residual
        // is zero and the "Doprava a poplatky" line must not appear. It stays
        // only for documents issued before that change.
        $invoice = $this->invoice();
        $xml = (new PohodaXmlFormat)->writeOne($invoice, []);

        $this->assertStringNotContainsString('Doprava a poplatky', $xml);
    }

    public function test_a_legacy_document_without_shipping_still_reconciles(): void
    {
        $invoice = $this->invoice();

        // A document in the pre-2.12 shape: shipping only in the recap.
        $items = array_values(array_filter(
            $invoice->items,
            static fn (array $i) => ! str_contains($i['name'], 'Doprava') && ! str_contains($i['name'], 'Platba'),
        ));
        \DB::table('documents')->where('id', $invoice->id)->update(['items' => json_encode($items)]);

        $xml = (new PohodaXmlFormat)->writeOne($invoice->fresh(), []);

        $this->assertStringContainsString('Doprava a poplatky', $xml);
    }

    public function test_a_non_vat_payer_document_is_internally_consistent(): void
    {
        // A non-VAT payer has an empty vat_summary, so there is no per-rate
        // residual to derive. TaxExclusiveAmount + TaxAmount must still equal
        // PayableAmount, or the ISDOC block contradicts itself.
        $invoice = $this->invoice();
        \DB::table('documents')->where('id', $invoice->id)->update(['vat_summary' => json_encode([])]);

        $xml = (new IsdocFormat)->writeOne($invoice->fresh(), []);
        $dom = new \DOMDocument;
        $dom->loadXML($xml);

        $value = fn (string $tag): float => (float) $dom->getElementsByTagName($tag)->item(0)?->nodeValue;

        $this->assertEqualsWithDelta(
            $value('PayableAmount'),
            $value('TaxExclusiveAmount') + $value('TaxAmount'),
            0.001,
            'ISDOC si nesmí odporovat: základ + daň se musí rovnat částce k úhradě.'
        );
    }
```

- [ ] **Krok 2: Spusť testy, ověř pád**

Spusť: `php artisan test --compact tests/Feature/Modules/Accounting`
Očekávej: FAIL — u prvního testu proto, že dopočet ještě běží; u třetího proto, že bez sazeb v rekapitulaci se souhrn rozejde.

- [ ] **Krok 3: Uprav dopočet a souhrny**

V `DocumentLines` zůstává dopočet zbytku beze změny pro doklady, kde zbytek vyjde nenulový (staré doklady) — u nových vyjde nula a řádek nevznikne sám od sebe. Ověř to čtením kódu; pokud se řádek přesto vkládá s nulou, přidej krátký guard a v komentáři vysvětli proč.

Pro **neplátce DPH** (prázdná `vat_summary`) uprav ISDOC tak, aby `TaxExclusiveAmount` odpovídalo součtu čistých řádků a `TaxAmount` bylo nula, takže `TaxExclusiveAmount + TaxAmount = PayableAmount = documents.total`. Nikdy nedopočítávej daň, kterou doklad neúčtuje.

- [ ] **Krok 4: Regeneruj golden files a doplň hodnotové aserce**

Golden files teď nesou dva řádky navíc. Regeneruj je stejným postupem jako ve vlně 2.11 (dočasná testovací metoda, která soubor zapíše před rollbackem, pak ji smaž) a **přečti je očima**. V hodnotových ascercích zkontroluj, že součet řádků odpovídá `documents.total`.

- [ ] **Krok 5: Spusť testy**

Spusť: `php artisan test --compact tests/Feature/Modules/Accounting`
Očekávej: PASS.

- [ ] **Krok 6: Commit**

```bash
./vendor/bin/pint Modules/Accounting tests/Feature/Modules/Accounting
git add Modules/Accounting tests
git commit -m "fix(accounting): keep the export consistent once the snapshot carries shipping"
```

---

### Task 3: Validace ISDOC proti oficiálnímu XSD

**Soubory:**
- Vytvořit: `tests/Fixtures/isdoc/isdoc-invoice-6.0.1.xsd`, `tests/Fixtures/isdoc/README.md`, `tests/Feature/Modules/Accounting/IsdocSchemaTest.php`

**Rozhraní:** žádné produkční API; test může odhalit, že `IsdocFormat` je potřeba opravit — pak se opravuje **formát**, ne test.

- [ ] **Krok 1: Stáhni schéma**

```bash
mkdir -p tests/Fixtures/isdoc
curl -fsSL https://isdoc.cz/6.0.1/xsd/isdoc-invoice-6.0.1.xsd -o tests/Fixtures/isdoc/isdoc-invoice-6.0.1.xsd
wc -c tests/Fixtures/isdoc/isdoc-invoice-6.0.1.xsd   # ~140 kB
```

Do `tests/Fixtures/isdoc/README.md` napiš původ (URL, datum stažení, verze 6.0.1) a že jde o cizí schéma, které se needituje.

- [ ] **Krok 2: Napiš test**

```php
<?php

namespace Tests\Feature\Modules\Accounting;

use App\Core\Documents\Contracts\DocumentIssuer;
use Modules\Accounting\Support\IsdocFormat;
use Modules\Docs\Models\Document;
use Modules\Orders\Models\Order;
use Tests\Feature\Modules\Docs\Support\DocsTestCase;

/**
 * Validates the generated ISDOC against the official 6.0.1 schema. Until wave
 * 2.12 correctness rested on reading the documentation; the golden files only
 * guard drift. libxml does the validation — no new dependency.
 */
class IsdocSchemaTest extends DocsTestCase
{
    private function assertValidIsdoc(string $xml): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument;
        $dom->loadXML($xml);

        $valid = $dom->schemaValidate(base_path('tests/Fixtures/isdoc/isdoc-invoice-6.0.1.xsd'));
        $errors = array_map(
            static fn (\LibXMLError $e): string => trim($e->message).' (řádek '.$e->line.')',
            libxml_get_errors(),
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($valid, "ISDOC neprošel validací proti XSD:\n".implode("\n", $errors));
    }

    public function test_an_invoice_validates_against_the_official_schema(): void
    {
        app(DocumentIssuer::class)->issue($this->placePaidOrder(), Document::TYPE_INVOICE);
        $invoice = Document::query()->where('type', Document::TYPE_INVOICE)->latest('id')->firstOrFail();

        $this->assertValidIsdoc((new IsdocFormat)->writeOne($invoice, []));
    }

    public function test_a_credit_note_validates_against_the_official_schema(): void
    {
        $uuid = $this->placePaidOrder();
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_INVOICE);
        Order::query()->where('uuid', $uuid)->update(['fulfillment_status' => Order::FULFILLMENT_CANCELLED]);
        app(DocumentIssuer::class)->issue($uuid, Document::TYPE_CREDIT_NOTE);

        $note = Document::query()->where('type', Document::TYPE_CREDIT_NOTE)->latest('id')->firstOrFail();

        $this->assertValidIsdoc((new IsdocFormat)->writeOne($note, []));
    }
}
```

- [ ] **Krok 3: Spusť test a oprav formát**

Spusť: `php artisan test --filter=IsdocSchemaTest`

**Očekávej pád.** Formát byl psán z dokumentace, ne z XSD, takže tohle je první skutečné ověření. Pravděpodobné nálezy: chybějící povinné elementy, špatné pořadí prvků v sekvenci, chybějící atributy na `Invoice`. **Oprav `IsdocFormat`, nikdy ne test ani schéma.** Každou opravu popiš v reportu — je to informace, kterou nikdo jiný nemá.

Pokud selhání ukáže, že formát potřebuje data, která snímek nenese, zastav a nahlas to; nevymýšlej hodnoty.

- [ ] **Krok 4: Spusť dotčené testy**

Spusť: `php artisan test --compact tests/Feature/Modules/Accounting`
Očekávej: PASS včetně golden files (ty případně regeneruj po opravách formátu).

- [ ] **Krok 5: Commit**

```bash
./vendor/bin/pint Modules/Accounting tests/Feature/Modules/Accounting
git add tests/Fixtures/isdoc Modules/Accounting tests/Feature/Modules/Accounting
git commit -m "test(accounting): validate ISDOC against the official 6.0.1 schema"
```

---

### Task 4: Uzavření vlny

- [ ] **Krok 1: Plná sada**

Spusť: `php artisan test --compact` (na pozadí, sada trvá přes 7 minut)
Očekávej: PASS.

- [ ] **Krok 2: Ruční ověření**

`php artisan serve`, objednávka na demu, vystavit fakturu, otevřít PDF a **sečíst řádky očima** — musí dát „Celkem k úhradě". Pak export v obou formátech.

- [ ] **Krok 3: As-is a rozhodnutí**

`docs/as-is/2026-07-31-doprava-na-dokladu.md`; do `CLAUDE.md` rozhodnutí, že doklad nese dopravu i poplatek jako řádky a proč (součet řádků = částka k úhradě), a že ISDOC je nově validovaný proti XSD. Aktualizuj `docs/as-is/STATUS.md` a v as-is vlny 2.11 označ dluh 1 a 2 za vyřešený.

- [ ] **Krok 4: Uzavři vlnu**

Spusť skill `/finish-wave`.

---

## Sebekontrola plánu

**Pokrytí zadání:** bod 1 (PDF nesečte řádky) → Task 1; bod 2 (neplátce DPH v ISDOC) → Task 2 krok 3; bod 3 (ověření formátů) → Task 3 pro ISDOC, reálný import do Pohody zůstává vlastníkovi v pre-deploy checklistu.

**Konzistence typů:** nové řádky mají přesně klíče `name`/`quantity`/`unit_price`/`tax_rate`/`line_total`, tedy tvar, který už čte `invoice.blade.php` i `DocumentLines`. `TaxRates::findById(int): TaxRate` a `TaxRate::percent()` existují. `CreditNoteSnapshot` mapuje přes `items` genericky, takže nové řádky neguje bez úpravy — hlídá to test v Tasku 1.

**Známá rizika:**
- Task 1 rozbije existující testy, které počítají položky dokladu. Je to očekávané; plán to říká v kroku 5, aby to implementer nezaměnil za regresi.
- Task 3 s velkou pravděpodobností odhalí chyby v `IsdocFormat`. To je jeho účel — proto je zařazen až za Task 2, aby se opravoval hotový formát, ne rozpracovaný.
- Doklady vystavené před touhle vlnou zůstávají bez řádku dopravy. Vědomé (rozhodnutí 2) a v exportu je pokryté dopočtem, ale v PDF u nich rozdíl zůstane navždy.
