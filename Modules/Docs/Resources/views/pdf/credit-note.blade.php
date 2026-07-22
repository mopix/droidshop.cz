{{--
    Credit note PDF (spec §16.6), rendered by Modules\Docs\Jobs\GenerateDocumentPdf.
    Copied from pdf/invoice.blade.php (Task 8 plan) with three differences:
    title, a reference line to the corrected invoice, and no QR block — a
    credit note is never a request to pay.

    Everything below reads only from the immutable $document snapshot — never
    a live tenant/order/product read. Amounts are already negative on the
    snapshot (CreditNoteSnapshot) and print as-is, with the minus sign.
--}}
@php
    use App\Core\Money\Money;

    $supplier = $document->supplier ?? [];
    $customer = $document->customer ?? [];
    $billing = $customer['billing'] ?? [];
    $isVatPayer = (bool) ($supplier['vat_payer'] ?? false);
    $supplierAddress = $supplier['address'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <title>{{ $document->number }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10pt;
            color: #1a1a1a;
        }
        h1 {
            font-size: 16pt;
            margin: 0 0 16px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        .parties { margin-bottom: 16px; }
        .parties td {
            width: 50%;
            vertical-align: top;
            padding: 0 8px 0 0;
        }
        .party-title {
            font-weight: bold;
            margin-bottom: 4px;
        }
        .meta {
            margin-bottom: 16px;
            border: 1px solid #ccc;
        }
        .meta td {
            padding: 4px 8px;
            border: 1px solid #ccc;
        }
        .meta td.label {
            font-weight: bold;
            width: 30%;
            background: #f5f5f5;
        }
        table.items {
            margin-bottom: 16px;
        }
        table.items th, table.items td {
            border: 1px solid #ccc;
            padding: 4px 6px;
            text-align: left;
        }
        table.items th {
            background: #f5f5f5;
        }
        table.items td.num, table.items th.num {
            text-align: right;
        }
        table.vat-summary {
            margin-bottom: 16px;
            width: 60%;
        }
        table.vat-summary th, table.vat-summary td {
            border: 1px solid #ccc;
            padding: 4px 6px;
            text-align: right;
        }
        table.vat-summary th {
            background: #f5f5f5;
        }
        .total-row td {
            font-weight: bold;
            font-size: 12pt;
            padding-top: 8px;
        }
        .footer {
            margin-top: 24px;
            padding-top: 8px;
            border-top: 1px solid #ccc;
            font-size: 8pt;
            color: #555;
        }
    </style>
</head>
<body>
    <h1>{{ $isVatPayer ? 'Opravný daňový doklad – dobropis' : 'Dobropis' }}</h1>

    <table class="parties">
        <tr>
            <td>
                <div class="party-title">Dodavatel</div>
                {{ $supplier['name'] ?? '' }}<br>
                @if(!empty($supplierAddress['street']))
                    {{ $supplierAddress['street'] }}<br>
                @endif
                @if(!empty($supplierAddress['zip']) || !empty($supplierAddress['city']))
                    {{ $supplierAddress['zip'] ?? '' }} {{ $supplierAddress['city'] ?? '' }}<br>
                @endif
                @if(!empty($supplierAddress['country']))
                    {{ $supplierAddress['country'] }}<br>
                @endif
                @if(!empty($supplier['ico']))
                    IČO: {{ $supplier['ico'] }}<br>
                @endif
                @if($isVatPayer && !empty($supplier['dic']))
                    DIČ: {{ $supplier['dic'] }}<br>
                @endif
            </td>
            <td>
                <div class="party-title">Odběratel</div>
                {{ $billing['name'] ?? ($customer['email'] ?? '') }}<br>
                @if(!empty($billing['company']))
                    {{ $billing['company'] }}<br>
                @endif
                @if(!empty($billing['street']))
                    {{ $billing['street'] }}<br>
                @endif
                @if(!empty($billing['zip']) || !empty($billing['city']))
                    {{ $billing['zip'] ?? '' }} {{ $billing['city'] ?? '' }}<br>
                @endif
                @if(!empty($billing['country']))
                    {{ $billing['country'] }}<br>
                @endif
                @if(!empty($billing['ico']))
                    IČO: {{ $billing['ico'] }}<br>
                @endif
                @if(!empty($billing['dic']))
                    DIČ: {{ $billing['dic'] }}<br>
                @endif
                @if(!empty($customer['email']))
                    E-mail: {{ $customer['email'] }}<br>
                @endif
                @if(!empty($customer['phone']))
                    Tel.: {{ $customer['phone'] }}<br>
                @endif
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="label">Číslo dokladu</td>
            <td>{{ $document->number }}</td>
            <td class="label">Opravovaný doklad</td>
            <td>{{ $document->corrects_number ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Datum vystavení</td>
            <td>{{ optional($document->issued_at)->format('d.m.Y') }}</td>
            <td class="label">Datum splatnosti</td>
            <td>{{ optional($document->due_at)->format('d.m.Y') }}</td>
        </tr>
        <tr>
            <td class="label">DUZP</td>
            <td>{{ optional($document->taxable_at)->format('d.m.Y') }}</td>
            <td class="label">Číslo objednávky</td>
            <td>{{ $customer['order_number'] ?? '' }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Položka</th>
                <th class="num">Množství</th>
                <th class="num">Jedn. cena</th>
                <th class="num">Sazba DPH</th>
                <th class="num">Celkem</th>
            </tr>
        </thead>
        <tbody>
            @foreach($document->items ?? [] as $item)
                <tr>
                    <td>{{ $item['name'] ?? '' }}</td>
                    <td class="num">{{ $item['quantity'] ?? 0 }}</td>
                    <td class="num">{{ (new Money((int) ($item['unit_price'] ?? 0), $document->currency))->format() }}</td>
                    <td class="num">{{ $item['tax_rate'] ?? '' }} %</td>
                    <td class="num">{{ (new Money((int) ($item['line_total'] ?? 0), $document->currency))->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($isVatPayer && !empty($document->vat_summary))
        <table class="vat-summary">
            <thead>
                <tr>
                    <th>Sazba DPH</th>
                    <th>Základ</th>
                    <th>DPH</th>
                    <th>Celkem</th>
                </tr>
            </thead>
            <tbody>
                @foreach($document->vat_summary as $row)
                    @php
                        $base = (int) ($row['base'] ?? 0);
                        $vat = (int) ($row['vat'] ?? 0);
                    @endphp
                    <tr>
                        <td>{{ $row['rate'] ?? 0 }} %</td>
                        <td>{{ (new Money($base, $document->currency))->format() }}</td>
                        <td>{{ (new Money($vat, $document->currency))->format() }}</td>
                        <td>{{ (new Money($base + $vat, $document->currency))->format() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table>
        <tr class="total-row">
            <td style="text-align: right;">Celkem k úhradě: {{ $document->total->format() }}</td>
        </tr>
    </table>

    @if(!empty($footer))
        <div class="footer">
            {{ $footer }}
        </div>
    @endif
</body>
</html>
