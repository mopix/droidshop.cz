<p>Dobrý den,</p>

<p>stav vaší objednávky č. {{ $orderNumber }} v e-shopu {{ $shopName }} se změnil.</p>

<p><strong>Nový stav: {{ $statusLabel }}</strong></p>

@if ($note)
    <p>{{ $note }}</p>
@endif

<p>Děkujeme, že nakupujete u {{ $shopName }}.</p>
