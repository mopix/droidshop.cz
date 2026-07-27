@extends('storefront::layouts.shop')

@section('content')
    <div class="mx-auto max-w-2xl">
        <p>
            <a href="{{ route('storefront.customers.account.orders') }}" class="text-sm text-slate-600 hover:text-brand hover:underline">
                &larr; Zpět na moje objednávky
            </a>
        </p>

        <h1 class="mt-2 text-2xl font-semibold text-slate-900 sm:text-3xl">Objednávka č. {{ $order->orderNumber() }}</h1>

        <dl class="card mt-4 grid grid-cols-2 gap-x-4 gap-y-2 p-4 text-sm sm:w-2/3">
            <dt class="text-slate-600">Datum</dt>
            <dd class="text-slate-900">{{ $order->orderPlacedAt()?->format('d.m.Y H:i') ?? '—' }}</dd>

            <dt class="text-slate-600">Stav objednávky</dt>
            <dd><span class="badge bg-slate-100 text-slate-800">{{ \Modules\Customers\Support\OrderStatusLabels::fulfillment($order->orderFulfillmentStatus()) }}</span></dd>

            <dt class="text-slate-600">Stav platby</dt>
            <dd><span class="badge bg-slate-100 text-slate-800">{{ \Modules\Customers\Support\OrderStatusLabels::payment($order->orderPaymentStatus()) }}</span></dd>
        </dl>

        @if ($pickupPoint !== null)
            <section class="card mt-6 p-4" aria-label="Výdejní místo">
                <h2 class="text-lg font-medium text-slate-900">Výdejní místo</h2>
                <p class="mt-1 text-sm text-slate-700">
                    <span class="font-medium">{{ $pickupPoint['name'] }}</span><br>
                    {{ $pickupPoint['street'] }}, {{ $pickupPoint['zip'] }} {{ $pickupPoint['city'] }}
                </p>
            </section>
        @endif

        @if ($shipment !== null && $trackingUrl !== null)
            <section class="card mt-6 p-4" aria-label="Sledování zásilky">
                <h2 class="text-lg font-medium text-slate-900">Sledování zásilky</h2>
                <p class="mt-1 text-sm text-slate-600">Číslo zásilky: {{ $shipment->shipmentBarcode() }}</p>
                <a
                    href="{{ $trackingUrl }}"
                    rel="nofollow noopener"
                    target="_blank"
                    class="mt-2 inline-block text-brand underline"
                >
                    Sledovat zásilku
                    <span class="sr-only">(odkaz se otevře v novém okně na webu dopravce)</span>
                </a>
            </section>
        @endif

        @if ($documents->isNotEmpty())
            <section class="mt-6" aria-label="Doklady">
                <h2 class="text-lg font-medium text-slate-900">Doklady</h2>

                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($documents as $document)
                        <li>
                            <a
                                href="{{ route('storefront.docs.download', ['number' => $document->documentNumber()]) }}"
                                class="text-slate-700 underline hover:text-brand"
                            >
                                Stáhnout fakturu č. {{ $document->documentNumber() }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="card mt-8 p-4" aria-label="Položky objednávky">
            <h2 class="text-lg font-medium text-slate-900">Položky</h2>

            <ul class="mt-2 divide-y divide-slate-100 text-sm text-slate-700">
                @foreach ($order->orderItems() as $item)
                    <li class="flex justify-between gap-2 py-2">
                        <span>{{ $item->quantity }}&times; {{ $item->name }}</span>
                        <span class="whitespace-nowrap font-medium text-slate-900">{{ $item->line_total->format() }}</span>
                    </li>
                @endforeach
            </ul>

            <dl class="mt-3 space-y-1 border-t border-slate-200 pt-3 text-sm text-slate-700">
                <div class="flex justify-between">
                    <dt>Mezisoučet</dt>
                    <dd>{{ $order->orderItemsTotal()->format() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Doprava</dt>
                    <dd>{{ $order->orderShippingTotal()->isZero() ? 'zdarma' : $order->orderShippingTotal()->format() }}</dd>
                </div>
                <div class="flex justify-between border-t border-slate-200 pt-2 text-lg font-semibold text-slate-900">
                    <dt>Celkem</dt>
                    <dd>{{ $order->orderTotal()->format() }}</dd>
                </div>
            </dl>
        </section>
    </div>
@endsection
