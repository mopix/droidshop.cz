@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">Doprava a platba</h1>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($usingFallback)
        {{--
            The shipping module is off (absent or deactivated) for this
            shop — ShippingOptions::available() answered empty. There is
            nothing to choose from, so the step is skipped rather than
            showing an empty radio list (plan decision 1): a single,
            already-decided delivery method instead.
        --}}
        <div class="mt-6 rounded border border-slate-200 bg-slate-50 p-4">
            <p class="font-medium">Osobní odběr — zdarma</p>
            <p class="mt-1 text-sm text-slate-600">
                Tento e-shop momentálně nenabízí výběr dopravy. Objednávku si vyzvednete osobně, bez poplatku.
            </p>
        </div>
    @else
        <form method="POST" action="{{ route('storefront.checkout.chooseShipping') }}" class="mt-6 space-y-6">
            @csrf

            <fieldset>
                <legend class="font-medium">Způsob dopravy</legend>
                <div class="mt-2 space-y-2">
                    @foreach ($shippingOptions as $option)
                        <label class="flex items-center gap-3 rounded border border-slate-200 p-3">
                            <input type="radio" name="shipping_method_id" value="{{ $option->id() }}"
                                   @checked($selectedShipping?->id() === $option->id())>
                            <span class="flex-1">{{ $option->name() }}</span>
                            <span>{{ $option->price()->format() }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @if ($selectedShipping !== null)
                <fieldset>
                    <legend class="font-medium">Způsob platby</legend>
                    <div class="mt-2 space-y-2">
                        @forelse ($paymentOptions as $option)
                            <label class="flex items-center gap-3 rounded border border-slate-200 p-3">
                                <input type="radio" name="payment_method_id" value="{{ $option->id() }}"
                                       @checked($selectedPayment?->id() === $option->id())>
                                <span class="flex-1">{{ $option->name() }}</span>
                                <span>{{ $option->fee()->isZero() ? '' : $option->fee()->format() }}</span>
                            </label>
                        @empty
                            <p class="text-sm text-slate-600">Pro tuto dopravu není žádná platba k dispozici.</p>
                        @endforelse
                    </div>
                </fieldset>
            @endif

            <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-white">Pokračovat</button>
        </form>
    @endif

    <p class="mt-6 text-right text-xl font-semibold">
        Celkem: {{ $total->format() }}
    </p>
@endsection
