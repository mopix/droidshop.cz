<p>Dobrý den,</p>

<p>ve vašem e-shopu {{ $shopName }} byla přijata nová objednávka č. {{ $orderNumber }}.</p>

<h2>Zákazník</h2>
<p>
    {{ $customerName }}<br>
    {{ $customerEmail }}
</p>

<h2>Souhrn objednávky</h2>

<table cellpadding="4" cellspacing="0">
    <thead>
        <tr>
            <th align="left">Položka</th>
            <th align="right">Počet</th>
            <th align="right">Cena</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td align="left">{{ $line['name'] }}</td>
                <td align="right">{{ $line['quantity'] }}</td>
                <td align="right">{{ $line['lineTotal'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p><strong>Celkem: {{ $total }}</strong></p>

<p>Způsob platby: {{ $paymentLabel }}</p>
