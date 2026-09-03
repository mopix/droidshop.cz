@extends('storefront::layouts.shop')

{{--
    Blade SSR, no JS required (.claude/rules/storefront-rendering.md): star
    ratings are a radio group, the only control that both works without
    JavaScript and that a screen reader announces as a single question with
    a chosen answer. The page carries noindex via $seo (set by the
    controller) — it is a personal, single-use link, not a page to rank.
--}}
@section('content')
    <h1 class="text-2xl font-semibold text-slate-900 sm:text-3xl">Vaše hodnocení</h1>

    <p class="mt-2 text-slate-600">Děkujeme za nákup. Ohodnoťte prosím, co jste si koupili.</p>

    @if ($errors->any())
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ route('storefront.reviews.store', ['token' => $token]) }}" class="mt-6 space-y-8">
        @csrf

        @if ($shopReviewsEnabled)
            <fieldset class="card space-y-3 p-6">
                <legend class="text-lg font-semibold text-slate-900">Jak jste byli spokojeni s obchodem?</legend>

                <div class="flex flex-wrap gap-4" role="radiogroup" aria-label="Hodnocení obchodu">
                    @for ($star = 5; $star >= 1; $star--)
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="shop[rating]" value="{{ $star }}" @checked(old('shop.rating') == $star)>
                            <span>{{ $star }} {{ $star === 1 ? 'hvězda' : ($star < 5 ? 'hvězdy' : 'hvězd') }}</span>
                        </label>
                    @endfor
                </div>

                <div>
                    <label for="shop-body" class="mb-1 block text-sm font-medium text-slate-700">Co byste dodali?</label>
                    <textarea id="shop-body" name="shop[body]" rows="3" class="field-input">{{ old('shop.body') }}</textarea>
                </div>
            </fieldset>
        @endif

        @foreach ($products as $id => $product)
            <fieldset class="card space-y-3 p-6">
                <legend class="text-lg font-semibold text-slate-900">{{ $product->catalogName() }}</legend>

                <div class="flex flex-wrap gap-4" role="radiogroup" aria-label="Hodnocení produktu {{ $product->catalogName() }}">
                    @for ($star = 5; $star >= 1; $star--)
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="products[{{ $id }}][rating]" value="{{ $star }}"
                                   @checked(old("products.$id.rating") == $star)>
                            <span>{{ $star }} {{ $star === 1 ? 'hvězda' : ($star < 5 ? 'hvězdy' : 'hvězd') }}</span>
                        </label>
                    @endfor
                </div>

                <div>
                    <label for="body-{{ $id }}" class="mb-1 block text-sm font-medium text-slate-700">Vaše zkušenost</label>
                    <textarea id="body-{{ $id }}" name="products[{{ $id }}][body]" rows="3" class="field-input">{{ old("products.$id.body") }}</textarea>
                </div>
            </fieldset>
        @endforeach

        <button type="submit" class="btn btn-primary">Odeslat hodnocení</button>
    </form>
@endsection
