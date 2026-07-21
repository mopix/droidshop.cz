@extends('storefront::layouts.shop')

@section('content')
    <div class="mx-auto max-w-2xl">
        <h1 class="text-2xl font-semibold">Děkujeme za objednávku</h1>

        @if (session('status'))
            <p class="mt-4 rounded border border-slate-300 bg-slate-50 p-4 text-slate-800">
                {{ session('status') }}
            </p>
        @endif

        <p class="mt-4 rounded border border-green-200 bg-green-50 p-4 text-green-800">
            Vaše objednávka č. <strong>{{ $order->orderNumber() }}</strong> byla přijata.
            Potvrzení jsme odeslali e-mailem.
        </p>

        {{-- Online gateway status. Gated on the online provider so these
             payment-state hints never collide with the offline bank-transfer
             QR block below (bank transfer / cod stay "unpaid" by nature and
             must not read "čeká na potvrzení platby"). --}}
        @php($paymentProvider = $order->orderPaymentSnapshot()['provider'] ?? null)
        @php($paymentStatus = $order->orderPaymentStatus())

        @if ($paymentProvider === 'comgate')
            @if ($paymentStatus === 'paid')
                <p class="mt-4 rounded border border-green-200 bg-green-50 p-4 text-green-800">
                    <strong>Platba přijata.</strong> Vaši objednávku evidujeme jako zaplacenou.
                </p>
            @elseif ($paymentStatus === 'failed')
                <div class="mt-4 rounded border border-red-200 bg-red-50 p-4 text-red-800">
                    <p><strong>Platba se nezdařila.</strong></p>
                    <p class="mt-1 text-sm">
                        Objednávka nebyla uhrazena. Můžete to zkusit znovu novým nákupem v našem obchodě.
                    </p>
                    <p class="mt-3">
                        <a href="{{ url('/') }}" class="inline-block rounded bg-slate-900 px-4 py-2 text-sm text-white">
                            Zpět do e-shopu
                        </a>
                    </p>
                </div>
            @elseif ($paymentStatus === 'unpaid')
                <p class="mt-4 rounded border border-amber-200 bg-amber-50 p-4 text-amber-900">
                    <strong>Čeká na potvrzení platby.</strong>
                    Jakmile platbu obdržíme, objednávku začneme zpracovávat.
                </p>
            @endif
        @endif

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
