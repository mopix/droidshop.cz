@extends('storefront::layouts.shop')

@section('content')
    <x-storefront::breadcrumbs :items="collect($ancestors)
        ->map(fn ($parent) => ['label' => $parent->name, 'url' => $parent->url()])
        ->push(['label' => $category->name, 'url' => $category->url()])
        ->prepend(['label' => 'Úvod', 'url' => '/'])
        ->all()" />

    <h1 class="text-2xl sm:text-3xl">{{ $category->name }}</h1>

    @if ($category->description_above)
        {{-- Sanitised on write by HtmlSanitizer; sanitising again here would
             put the policy in two places. --}}
        <div class="prose-shop mt-3 max-w-3xl">{!! $category->description_above !!}</div>
    @endif

    @if ($children->isNotEmpty())
        {{-- Subcategories as picture chips: a catalogue shopper narrows by
             department before they narrow by price. --}}
        <nav aria-label="Podkategorie" class="mt-5">
            <ul class="flex flex-wrap gap-3">
                @foreach ($children as $child)
                    <li>
                        <a href="{{ $child->url() }}"
                           class="card flex items-center gap-3 px-3 py-2 text-sm font-medium text-ink transition hover:border-brand">
                            <span class="block h-10 w-10 shrink-0 rounded-token bg-surface-muted" aria-hidden="true"></span>
                            {{ $child->name }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    @if ($products->total() === 0)
        <p class="mt-8 text-ink-muted">V této kategorii zatím nic nenabízíme.</p>
    @else
        <div class="mt-6 lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-6">
            <div class="mb-6 lg:mb-0">
                <x-storefront::facet-panel :facets="$facets" :query="$query" />
            </div>

            <div>
                {{-- The toolbar: how many, in what order, how many per page,
                     and where in the pages you are — all in one plain GET form
                     plus the paginator. The shared sort form carries its own
                     bottom margin for standalone use; inside the bar it would
                     push the count out of line. --}}
                <div class="card mb-4 flex flex-wrap items-center justify-between gap-4 p-3 [&_form]:mb-0">
                    <p class="text-sm text-ink-muted">
                        <x-storefront::product-count :count="$products->total()" />
                    </p>

                    <x-storefront::sort-form :query="$query" />
                </div>

                @if ($products->hasPages())
                    <div class="mb-4">{{ $products->links() }}</div>
                @endif

                <x-storefront::product-grid :products="$products" />

                <div class="mt-6">{{ $products->links() }}</div>
            </div>
        </div>
    @endif

    @if ($category->description_below)
        {{-- The shop's own words about the category, under the goods where a
             search engine expects them and a shopper is not blocked by them. --}}
        <div class="prose-shop mt-10 border-t border-line pt-8">{!! $category->description_below !!}</div>
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
