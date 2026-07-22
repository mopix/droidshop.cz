@extends('storefront::layouts.shop')

@section('content')
    <div class="mx-auto max-w-2xl">
        <p>
            <a href="{{ route('storefront.customers.account.orders') }}" class="text-sm text-slate-600 hover:underline">
                &larr; Zpět na moje objednávky
            </a>
        </p>

        <h1 class="mt-2 text-2xl font-semibold">Objednávka č. {{ $order->orderNumber() }}</h1>

        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:w-2/3">
            <dt class="text-slate-600">Datum</dt>
            <dd>{{ $order->orderPlacedAt()?->format('d.m.Y H:i') ?? '—' }}</dd>

            <dt class="text-slate-600">Stav objednávky</dt>
            <dd>{{ \Modules\Customers\Support\OrderStatusLabels::fulfillment($order->orderFulfillmentStatus()) }}</dd>

            <dt class="text-slate-600">Stav platby</dt>
            <dd>{{ \Modules\Customers\Support\OrderStatusLabels::payment($order->orderPaymentStatus()) }}</dd>
        </dl>

        @if ($documents->isNotEmpty())
            <section class="mt-6" aria-label="Doklady">
                <h2 class="text-lg font-medium">Doklady</h2>

                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($documents as $document)
                        <li>
                            <a
                                href="{{ route('storefront.docs.download', ['number' => $document->documentNumber()]) }}"
                                class="text-slate-700 underline hover:text-slate-900"
                            >
                                Stáhnout fakturu č. {{ $document->documentNumber() }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="mt-8" aria-label="Položky objednávky">
            <h2 class="text-lg font-medium">Položky</h2>

            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                @foreach ($order->orderItems() as $item)
                    <li class="flex justify-between gap-2 py-2">
                        <span>{{ $item->quantity }}&times; {{ $item->name }}</span>
                        <span class="whitespace-nowrap">{{ $item->line_total->format() }}</span>
                    </li>
                @endforeach
            </ul>

            <dl class="mt-3 space-y-1 border-t border-slate-200 pt-3 text-sm">
                <div class="flex justify-between">
                    <dt>Mezisoučet</dt>
                    <dd>{{ $order->orderItemsTotal()->format() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Doprava</dt>
                    <dd>{{ $order->orderShippingTotal()->isZero() ? 'zdarma' : $order->orderShippingTotal()->format() }}</dd>
                </div>
                <div class="flex justify-between border-t border-slate-200 pt-2 text-lg font-semibold">
                    <dt>Celkem</dt>
                    <dd>{{ $order->orderTotal()->format() }}</dd>
                </div>
            </dl>
        </section>
    </div>
@endsection
