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
        <div class="prose-shop mt-4 max-w-3xl">{!! $category->description_above !!}</div>
    @endif

    @if ($children->isNotEmpty())
        {{-- Subcategories as chips above the listing, the catalogue way in:
             a shopper narrows by department before they narrow by price. --}}
        <nav aria-label="Podkategorie" class="mt-6">
            <ul class="flex flex-wrap gap-2">
                @foreach ($children as $child)
                    <li>
                        <a href="{{ $child->url() }}"
                           class="card inline-block px-4 py-2 text-sm font-medium text-ink transition hover:border-brand">
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
        {{--
            The toolbar: count on the left, sorting on the right, in one plain
            GET form. Not a drawer — this theme's shopper compares rows and
            wants the control in sight, and a form in the flow needs no script
            to open.
        --}}
        {{-- The shared sort form carries its own bottom margin for use on its
             own; inside this bar it would push the count out of line. --}}
        <div class="card mt-6 flex flex-wrap items-center justify-between gap-4 p-4 [&_form]:mb-0">
            <p class="text-sm text-ink-muted">
                <x-storefront::product-count :count="$products->total()" />
            </p>

            <x-storefront::sort-form :query="$query" />
        </div>

        <div class="mt-6 lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-6">
            <div class="mb-6 lg:mb-0">
                <x-storefront::facet-panel :facets="$facets" :query="$query" />
            </div>

            <div>
                @if ($products->hasPages())
                    <div class="mb-4">{{ $products->links() }}</div>
                @endif

                <x-storefront::product-grid :products="$products" />

                <div class="mt-8">{{ $products->links() }}</div>
            </div>
        </div>
    @endif

    @if ($category->description_below)
        <div class="prose-shop mt-12 border-t border-line pt-8">{!! $category->description_below !!}</div>
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
