@extends('storefront::layouts.shop')

@section('content')
    <section class="border-b border-slate-100 pb-10">
        <h1 class="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">{{ $shopName }}</h1>
        <p class="mt-3 max-w-2xl text-slate-600">Vítejte v našem e-shopu. Podívejte se na aktuální nabídku.</p>
    </section>

    @if ($categories->isNotEmpty())
        <section class="mt-10" aria-labelledby="nadpis-kategorie">
            <h2 id="nadpis-kategorie" class="mb-4 text-lg font-semibold text-slate-900">Kategorie</h2>
            <ul class="flex flex-wrap gap-3">
                @foreach ($categories as $category)
                    <li>
                        <a href="{{ $category->url() }}" class="btn btn-outline">
                            {{ $category->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mt-12" aria-labelledby="nadpis-novinky">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 id="nadpis-novinky" class="text-lg font-semibold text-slate-900">Novinky</h2>

            {{-- Catalogue is category-based (no single "all products" page),
                 so the way into the full catalogue is the first category. --}}
            @if ($categories->isNotEmpty())
                <a href="{{ $categories->first()->url() }}" class="text-sm font-medium text-brand hover:underline">
                    Celá nabídka →
                </a>
            @endif
        </div>

        @if ($products->isEmpty())
            <p class="mt-4 text-slate-600">Nabídka se právě připravuje.</p>
        @else
            <div class="mt-6">
                <x-storefront::product-grid :products="$products" />
            </div>
        @endif
    </section>
@endsection

@push('head')
    <x-storefront::json-ld :data="[
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $shopName,
        'url' => url('/'),
    ]" />
    <x-storefront::json-ld :data="[
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $shopName,
        'url' => url('/'),
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => url('/hledani').'?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ]" />
@endpush
