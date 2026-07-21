@extends('storefront::layouts.shop')

@section('content')
    <div class="mx-auto max-w-2xl">
        <h1 class="text-2xl font-semibold">Děkujeme za objednávku</h1>

        <p class="mt-4 rounded border border-green-200 bg-green-50 p-4 text-green-800">
            Vaše objednávka č. <strong>{{ $order->orderNumber() }}</strong> byla přijata.
            Potvrzení jsme odeslali e-mailem.
        </p>

        <section class="mt-8" aria-label="Souhrn objednávky">
            <h2 class="text-lg font-medium">Souhrn</h2>

            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                @foreach ($order->orderItems() as $item)
                    <li class="flex justify-between gap-2 py-2">
                        <span>{{ $item->quantity }}× {{ $item->name }}</span>
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

        <section class="mt-8" aria-label="Platba">
            <h2 class="text-lg font-medium">Platba</h2>
            <p class="mt-2 text-sm">Způsob platby: {{ $paymentLabel }}</p>

            @if ($qrSvg)
                <div class="mt-4 rounded border border-slate-200 p-4">
                    <p class="text-sm">
                        Zaplaťte prosím převodem na účet
                        <strong>{{ $bankAccount }}</strong>,
                        částku <strong>{{ $order->orderTotal()->format() }}</strong>,
                        variabilní symbol <strong>{{ $variableSymbol }}</strong>.
                    </p>
                    <div class="mt-4 max-w-[240px]" role="img" aria-label="QR kód pro platbu převodem">
                        {!! $qrSvg !!}
                    </div>
                </div>
            @endif
        </section>

        <p class="mt-8">
            <a href="/" class="rounded bg-slate-900 px-4 py-2 text-white">Zpět do e-shopu</a>
        </p>
    </div>
@endsection
