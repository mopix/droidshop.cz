@extends('storefront::layouts.shop')

@section('content')
    <x-storefront::breadcrumbs :items="collect($ancestors)
        ->map(fn ($parent) => ['label' => $parent->name, 'url' => $parent->url()])
        ->push(['label' => $category->name, 'url' => $category->url()])
        ->prepend(['label' => 'Úvod', 'url' => '/'])
        ->all()" />

    <h1 class="text-2xl font-semibold">{{ $category->name }}</h1>

    @if ($category->description_above)
        {{-- Sanitised on write by HtmlSanitizer; sanitising again here would
             mean the policy lives in two places. --}}
        <div class="prose mt-4 max-w-none">{!! $category->description_above !!}</div>
    @endif

    @if ($children->isNotEmpty())
        <nav aria-label="Podkategorie" class="mt-6">
            <ul class="flex flex-wrap gap-3">
                @foreach ($children as $child)
                    <li>
                        <a href="{{ $child->url() }}" class="rounded border border-slate-200 px-4 py-2 hover:bg-slate-50">
                            {{ $child->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    <div class="mt-8">
        @if ($products->total() === 0)
            <p class="text-slate-600">V této kategorii zatím nic nenabízíme.</p>
        @else
            <x-storefront::sort-form :query="$query" />

            <x-storefront::product-grid :products="$products" />

            <div class="mt-8">
                {{ $products->links() }}
            </div>
        @endif
    </div>

    @if ($category->description_below)
        <div class="prose mt-10 max-w-none">{!! $category->description_below !!}</div>
    @endif
@endsection

@push('head')
    <x-storefront::json-ld :data="[
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => $category->name,
        'numberOfItems' => $products->total(),
        'itemListElement' => collect($products->items())->values()->map(fn ($product, $index) => [
            '@type' => 'ListItem',
            'position' => $products->firstItem() + $index,
            'url' => url($product->catalogUrl()),
            'name' => $product->catalogName(),
        ])->all(),
    ]" />
@endpush
