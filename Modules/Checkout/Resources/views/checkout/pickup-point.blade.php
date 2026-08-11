@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Výdejní místo</h1>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($widgetApiKey !== null)
        {{--
            Enhancement only. The widget script lives on Packeta's domain and
            is fetched on click, never on load: until the shopper asks for the
            map, checkout makes no third-party request at all (performance,
            ePrivacy, CSP). Everything below — search and the list — works
            without it. The container ships hidden and JS reveals it, so a
            shopper without JavaScript never sees a button that would do
            nothing.

            The @csrf field lives here, not only inside the results form
            below, because that form only renders once a search has matches
            — the widget button must carry a valid token on its own.
        --}}
        <div class="mt-6" data-packeta-widget data-api-key="{{ $widgetApiKey }}"
             data-action="{{ route('storefront.checkout.choosePickupPoint', $shippingMethodId !== null ? ['shipping_method_id' => $shippingMethodId] : []) }}" aria-live="polite" hidden>
            @csrf
            <button type="button" data-packeta-open class="btn btn-outline">Vybrat na mapě</button>
        </div>
    @endif

    {{--
        shipping_method_id (review finding I2) carries which option opened
        this picker through the search round trip — without it, searching
        would fall back to deriving the carrier from the cart's currently
        stored shipping method, which can be a different option entirely.
    --}}
    <form method="GET" action="{{ route('storefront.checkout.pickupPoint') }}" class="mt-6 flex gap-2">
        <label for="q" class="sr-only">Hledat výdejní místo</label>
        <input id="q" name="q" value="{{ $query }}" placeholder="Město, PSČ nebo název"
               class="field-input mt-0 flex-1">
        @if ($shippingMethodId !== null)
            <input type="hidden" name="shipping_method_id" value="{{ $shippingMethodId }}">
        @endif
        <button type="submit" class="btn btn-primary">Hledat</button>
    </form>

    @if ($query !== '' && $points->isEmpty())
        <p class="mt-6 text-slate-600">Pro „{{ $query }}“ jsme nic nenašli. Zkuste jiné město nebo PSČ.</p>
    @endif

    @if ($points->isNotEmpty())
        <form method="POST" action="{{ route('storefront.checkout.choosePickupPoint') }}" class="mt-6 space-y-4">
            @csrf
            @if ($shippingMethodId !== null)
                <input type="hidden" name="shipping_method_id" value="{{ $shippingMethodId }}">
            @endif

            <fieldset>
                <legend class="text-base font-medium text-slate-900">Vyberte místo</legend>
                <div class="mt-2 space-y-2">
                    @foreach ($points as $point)
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-brand has-[:checked]:bg-slate-50">
                            <input type="radio" name="pickup_point_code" value="{{ $point->pointCode() }}"
                                   class="mt-1 h-4 w-4 border-slate-300 text-brand focus:ring-brand"
                                   @checked($selected === $point->pointCode())>
                            <span class="flex-1">
                                <span class="block font-medium text-slate-900">{{ $point->pointName() }}</span>
                                <span class="block text-sm text-slate-600">
                                    {{ $point->pointStreet() }}, {{ $point->pointZip() }} {{ $point->pointCity() }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <button type="submit" class="btn btn-primary">Vybrat toto místo</button>
        </form>
    @endif

    <p class="mt-6">
        <a href="{{ route('storefront.checkout.shipping') }}" class="text-brand underline">Zpět na dopravu a platbu</a>
    </p>
@endsection
