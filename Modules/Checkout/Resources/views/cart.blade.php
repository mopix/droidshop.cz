@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Košík</h1>

    @if (session('status'))
        <p role="status" class="mt-4 rounded border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
            {{ session('status') }}
        </p>
    @endif

    @if ($cart->isEmpty())
        <p class="mt-6 text-slate-600">Váš košík je prázdný.</p>
        <p class="mt-4">
            <a href="/" class="rounded bg-slate-900 px-4 py-2 text-white">Pokračovat v nákupu</a>
        </p>
    @else
        @if ($cart->hasPriceChange)
            {{--
                AK 4: at least one line's snapshot no longer matches the
                catalogue. The total below is already recomputed from the
                current price — this banner only explains why it moved.
            --}}
            <div role="alert" class="mt-4 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Cena u některých položek se od vložení do košíku změnila. Součet níže je přepočítaný podle aktuálních cen.
            </div>
        @endif

        <ul class="mt-6 divide-y divide-slate-200">
            @foreach ($cart->lines as $line)
                <li class="flex flex-wrap items-center gap-4 py-4">
                    @if ($line->imageUrl)
                        <img src="{{ $line->imageUrl }}" alt="" class="h-16 w-16 rounded object-cover" loading="lazy">
                    @else
                        <div class="h-16 w-16 rounded bg-slate-100" aria-hidden="true"></div>
                    @endif

                    <div class="flex-1">
                        <p class="font-medium">
                            @if ($line->url)
                                <a href="{{ $line->url }}" class="hover:underline">{{ $line->name }}</a>
                            @else
                                {{ $line->name }}
                            @endif
                        </p>

                        @if ($line->priceChanged)
                            <p class="mt-1 text-sm text-amber-700">
                                Cena se změnila z {{ $line->previousUnitPrice->format() }} na {{ $line->unitPrice->format() }}.
                            </p>
                        @endif

                        @unless ($line->available)
                            <p class="mt-1 text-sm text-red-700">Tento produkt už není dostupný — odeberte jej z košíku.</p>
                        @endunless
                    </div>

                    <form method="POST" action="{{ route('storefront.checkout.update', $line->itemId) }}"
                          class="flex items-center gap-2">
                        @csrf
                        @method('PATCH')
                        <label for="mnozstvi-{{ $line->itemId }}" class="sr-only">Množství — {{ $line->name }}</label>
                        <input id="mnozstvi-{{ $line->itemId }}" type="number" name="quantity"
                               value="{{ $line->quantity }}" min="0" max="99" inputmode="numeric"
                               class="w-16 rounded border border-slate-300 px-2 py-1 text-sm">
                        <button type="submit" class="rounded border border-slate-300 px-3 py-1 text-sm">
                            Aktualizovat
                        </button>
                    </form>

                    <p class="w-28 text-right font-medium">{{ $line->lineTotal->format() }}</p>

                    <form method="POST" action="{{ route('storefront.checkout.remove', $line->itemId) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-red-700 underline">Odebrat</button>
                    </form>
                </li>
            @endforeach
        </ul>

        @if ($cart->freeShippingRemaining)
            <p class="mt-6 rounded bg-slate-50 p-3 text-sm">
                Do dopravy zdarma vám zbývá {{ $cart->freeShippingRemaining->format() }}.
            </p>
        @elseif ($cart->freeShippingThreshold)
            <p class="mt-6 rounded bg-emerald-50 p-3 text-sm text-emerald-800">Máte dopravu zdarma.</p>
        @endif

        <p class="mt-6 text-right text-xl font-semibold">
            Celkem: {{ $cart->itemsTotal->format() }}
        </p>

        <p class="mt-4 text-right">
            <a href="{{ route('storefront.checkout.shipping') }}" class="rounded bg-slate-900 px-4 py-2 text-white">
                Pokračovat k pokladně
            </a>
        </p>
    @endif
@endsection
