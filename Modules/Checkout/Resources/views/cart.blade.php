@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Košík</h1>

    @if (session('status'))
        <p role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
            {{ session('status') }}
        </p>
    @endif

    @if ($cart->isEmpty())
        <div class="card mt-6 p-6 text-slate-600">
            <p>Váš košík je prázdný.</p>
            <p class="mt-4">
                <a href="/" class="btn btn-primary">Pokračovat v nákupu</a>
            </p>
        </div>
    @else
        @if ($cart->hasPriceChange)
            {{--
                AK 4: at least one line's snapshot no longer matches the
                catalogue. The total below is already recomputed from the
                current price — this banner only explains why it moved.
            --}}
            <div role="alert" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Cena u některých položek se od vložení do košíku změnila. Součet níže je přepočítaný podle aktuálních cen.
            </div>
        @endif

        <div class="card mt-6 divide-y divide-slate-100">
            @foreach ($cart->lines as $line)
                <div class="flex flex-wrap items-center gap-4 p-4">
                    @if ($line->imageUrl)
                        <img src="{{ $line->imageUrl }}" alt="" class="h-16 w-16 shrink-0 rounded-lg object-cover" loading="lazy">
                    @else
                        <div class="h-16 w-16 shrink-0 rounded-lg bg-slate-100" aria-hidden="true"></div>
                    @endif

                    <div class="min-w-[10rem] flex-1">
                        <p class="font-medium text-slate-900">
                            @if ($line->url)
                                <a href="{{ $line->url }}" class="hover:text-brand hover:underline">{{ $line->name }}</a>
                            @else
                                {{ $line->name }}
                            @endif
                        </p>

                        @if ($line->variantLabel)
                            <p class="text-sm text-slate-500">{{ $line->variantLabel }}</p>
                        @endif

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
                               class="field-input mt-0 w-16 py-1">
                        <button type="submit" class="btn btn-outline px-3 py-1 text-sm">
                            Aktualizovat
                        </button>
                    </form>

                    <p class="w-28 text-right font-medium text-slate-900">{{ $line->lineTotal->format() }}</p>

                    <form method="POST" action="{{ route('storefront.checkout.remove', $line->itemId) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-red-700 underline hover:text-red-800">Odebrat</button>
                    </form>
                </div>
            @endforeach
        </div>

        @if ($cart->freeShippingRemaining)
            <p class="mt-6 rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
                Do dopravy zdarma vám zbývá {{ $cart->freeShippingRemaining->format() }}.
            </p>
        @elseif ($cart->freeShippingThreshold)
            <p class="mt-6 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">Máte dopravu zdarma.</p>
        @endif

        <p class="mt-6 text-right text-xl font-semibold text-slate-900">
            Celkem: {{ $cart->itemsTotal->format() }}
        </p>

        <p class="mt-4 text-right">
            <a href="{{ route('storefront.checkout.shipping') }}" class="btn btn-primary">
                Pokračovat k pokladně
            </a>
        </p>
    @endif
@endsection
