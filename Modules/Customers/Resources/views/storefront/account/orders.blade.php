@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Moje objednávky</h1>

    <p class="mt-2">
        <a href="{{ route('storefront.customers.account') }}" class="text-sm text-slate-600 hover:underline">
            &larr; Zpět do účtu
        </a>
    </p>

    @if ($orders->isEmpty())
        <p class="mt-6 text-sm text-slate-600">Zatím nemáte žádné objednávky.</p>
    @else
        <div class="mt-6 overflow-x-auto">
            <table class="w-full min-w-[560px] divide-y divide-slate-200 text-sm">
                <caption class="sr-only">Přehled vašich objednávek</caption>
                <thead>
                    <tr class="text-left text-slate-600">
                        <th scope="col" class="py-2 pr-4 font-medium">Číslo</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Datum</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Stav</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Platba</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Celkem</th>
                        <th scope="col" class="py-2 font-medium"><span class="sr-only">Detail</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($orders as $order)
                        <tr>
                            <td class="py-2 pr-4">{{ $order->orderNumber() }}</td>
                            <td class="py-2 pr-4">{{ $order->orderPlacedAt()?->format('d.m.Y') ?? '—' }}</td>
                            <td class="py-2 pr-4">{{ \Modules\Customers\Support\OrderStatusLabels::fulfillment($order->orderFulfillmentStatus()) }}</td>
                            <td class="py-2 pr-4">{{ \Modules\Customers\Support\OrderStatusLabels::payment($order->orderPaymentStatus()) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $order->orderTotal()->format() }}</td>
                            <td class="py-2">
                                <a href="{{ route('storefront.customers.account.orders.show', $order->orderUuid()) }}"
                                   class="rounded border border-slate-300 px-3 py-1 text-sm hover:border-slate-500">
                                    Detail
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
