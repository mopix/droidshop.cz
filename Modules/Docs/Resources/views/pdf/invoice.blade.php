{{--
    Invoice PDF (spec §16.6), rendered by Modules\Docs\Jobs\GenerateInvoicePdf.

    Everything below reads only from the immutable $document snapshot (plus
    the $qr data URI and $footer text resolved by the job) — never a live
    tenant/order/product read. A later change to the tenant's billing profile
    or a product price must not alter an already-issued invoice.

    dompdf has no flex/grid, so the layout is table-based throughout. Font is
    DejaVu Sans, dompdf's built-in Unicode font, which keeps Czech diacritics
    intact without embedding anything extra.
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
        .qr-block {
            margin-top: 16px;
            border-top: 1px solid #ccc;
            padding-top: 12px;
        }
        .qr-block img {
            width: 100px;
            height: 100px;
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
    <h1>{{ $isVatPayer ? 'Faktura – daňový doklad' : 'Faktura' }}</h1>

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
            <td class="label">Variabilní symbol</td>
            <td>{{ $customer['order_number'] ?? '' }}</td>
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

    @if($qr)
        <div class="qr-block">
            <table>
                <tr>
                    <td style="width: 110px;">
                        <img src="{{ $qr }}" alt="QR platba">
                    </td>
                    <td style="vertical-align: middle;">
                        Naskenujte QR kód platební aplikací a fakturu uhraďte,<br>
                        variabilní symbol {{ $customer['order_number'] ?? '' }}.
                    </td>
                </tr>
            </table>
        </div>
    @endif

    @if(!empty($footer))
        <div class="footer">
            {{ $footer }}
        </div>
    @endif
</body>
</html>
