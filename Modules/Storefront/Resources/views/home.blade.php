@extends('storefront::layouts.shop')

@section('content')
    <h1 class="text-2xl font-semibold">{{ $shopName }}</h1>

    @if ($categories->isNotEmpty())
        <section class="mt-8" aria-labelledby="nadpis-kategorie">
            <h2 id="nadpis-kategorie" class="mb-4 text-lg font-semibold">Kategorie</h2>
            <ul class="flex flex-wrap gap-3">
                @foreach ($categories as $category)
                    <li>
                        <a href="{{ $category->url() }}" class="rounded border border-slate-200 px-4 py-2 hover:bg-slate-50">
                            {{ $category->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="mt-10" aria-labelledby="nadpis-novinky">
        <h2 id="nadpis-novinky" class="mb-4 text-lg font-semibold">Novinky</h2>

        @if ($products->isEmpty())
            <p class="text-slate-600">Nabídka se právě připravuje.</p>
        @else
            <x-storefront::product-grid :products="$products" />
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
