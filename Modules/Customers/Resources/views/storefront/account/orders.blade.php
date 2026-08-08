@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Moje objednávky</h1>

    <p class="mt-2">
        <a href="{{ route('storefront.customers.account') }}" class="text-sm text-slate-600 hover:text-brand hover:underline">
            &larr; Zpět do účtu
        </a>
    </p>

    @if ($orders->isEmpty())
        <div class="card mt-6 p-6 text-sm text-slate-600">Zatím nemáte žádné objednávky.</div>
    @else
        <div class="card mt-6 overflow-x-auto">
            <table class="w-full min-w-[560px] divide-y divide-slate-200 text-sm">
                <caption class="sr-only">Přehled vašich objednávek</caption>
                <thead>
                    <tr class="text-left text-slate-600">
                        <th scope="col" class="py-3 pl-4 pr-4 font-medium">Číslo</th>
                        <th scope="col" class="py-3 pr-4 font-medium">Datum</th>
                        <th scope="col" class="py-3 pr-4 font-medium">Stav</th>
                        <th scope="col" class="py-3 pr-4 font-medium">Platba</th>
                        <th scope="col" class="py-3 pr-4 font-medium">Celkem</th>
                        <th scope="col" class="py-3 pr-4 font-medium"><span class="sr-only">Detail</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($orders as $order)
                        <tr>
                            <td class="py-3 pl-4 pr-4 font-medium text-slate-900">{{ $order->orderNumber() }}</td>
                            <td class="py-3 pr-4 text-slate-700">{{ app(\App\Core\Shop\ShopClock::class)->formatDate($order->orderPlacedAt()) ?? '—' }}</td>
                            <td class="py-3 pr-4 text-slate-700">{{ \Modules\Customers\Support\OrderStatusLabels::fulfillment($order->orderFulfillmentStatus()) }}</td>
                            <td class="py-3 pr-4 text-slate-700">{{ \Modules\Customers\Support\OrderStatusLabels::payment($order->orderPaymentStatus()) }}</td>
                            <td class="py-3 pr-4 whitespace-nowrap font-medium text-slate-900">{{ $order->orderTotal()->format() }}</td>
                            <td class="py-3 pr-4">
                                <a href="{{ route('storefront.customers.account.orders.show', $order->orderUuid()) }}"
                                   class="btn btn-outline px-3 py-1 text-sm">
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
