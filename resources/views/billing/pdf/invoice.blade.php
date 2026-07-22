{{--
    Subscription invoice PDF, rendered synchronously by PlatformInvoiceWriter
    onto the platform-private disk.

    Reads only from the immutable $invoice snapshot — supplier/customer are
    already frozen JSON, never a live tenant/config read. A later change to
    the platform's billing config or the tenant's billing profile must not
    alter an already-issued invoice.

    dompdf has no flex/grid, so the layout is table-based throughout. Font is
    DejaVu Sans, dompdf's built-in Unicode font, which keeps Czech diacritics
    intact without embedding anything extra.
--}}
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
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
    <h1>Faktura za předplatné {{ $invoice->number }}</h1>

    <table class="parties">
        <tr>
            <td>
                <div class="party-title">Dodavatel</div>
                {{ $invoice->supplier['name'] ?? '' }}<br>
                @if(!empty($invoice->supplier['address']))
                    {{ $invoice->supplier['address'] }}<br>
                @endif
                @if(!empty($invoice->supplier['ico']))
                    IČO: {{ $invoice->supplier['ico'] }}<br>
                @endif
                @if(!empty($invoice->supplier['vat_payer']) && !empty($invoice->supplier['dic']))
                    DIČ: {{ $invoice->supplier['dic'] }}<br>
                @endif
            </td>
            <td>
                <div class="party-title">Odběratel</div>
                {{ $invoice->customer['name'] ?? '' }}<br>
                @php($address = $invoice->customer['address'] ?? [])
                @if(!empty($address['street']))
                    {{ $address['street'] }}<br>
                @endif
                @if(!empty($address['zip']) || !empty($address['city']))
                    {{ $address['zip'] ?? '' }} {{ $address['city'] ?? '' }}<br>
                @endif
                @if(!empty($invoice->customer['ico']))
                    IČO: {{ $invoice->customer['ico'] }}<br>
                @endif
                @if(!empty($invoice->customer['dic']))
                    DIČ: {{ $invoice->customer['dic'] }}<br>
                @endif
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td class="label">Číslo dokladu</td>
            <td>{{ $invoice->number }}</td>
            <td class="label">Tarif</td>
            <td>{{ $invoice->plan_key }}</td>
        </tr>
        <tr>
            <td class="label">Období</td>
            <td>{{ $invoice->period_from->format('d.m.Y') }} – {{ $invoice->period_to->format('d.m.Y') }}</td>
            <td class="label">Datum vystavení</td>
            <td>{{ $invoice->issued_at->format('d.m.Y') }}</td>
        </tr>
        <tr>
            <td class="label">DUZP</td>
            <td>{{ $invoice->taxable_at->format('d.m.Y') }}</td>
            <td class="label"></td>
            <td></td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Popis</th>
                <th class="num">Bez DPH</th>
                <th class="num">Sazba DPH</th>
                <th class="num">DPH</th>
                <th class="num">Celkem</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Předplatné tarifu {{ $invoice->plan_key }}</td>
                <td class="num">{{ number_format($invoice->subtotal / 100, 2, ',', ' ') }} Kč</td>
                <td class="num">{{ $invoice->vat_rate }} %</td>
                <td class="num">{{ number_format($invoice->vat_amount / 100, 2, ',', ' ') }} Kč</td>
                <td class="num">{{ number_format($invoice->total / 100, 2, ',', ' ') }} Kč</td>
            </tr>
        </tbody>
    </table>

    <table>
        <tr class="total-row">
            <td style="text-align: right;">Celkem k úhradě: {{ number_format($invoice->total / 100, 2, ',', ' ') }} Kč</td>
        </tr>
    </table>

    @if(empty($invoice->vat_summary))
        <p>Dodavatel není plátcem DPH.</p>
    @endif
</body>
</html>
