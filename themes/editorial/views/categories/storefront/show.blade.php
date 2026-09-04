@extends('storefront::layouts.shop')

@section('content')
    {{--
        Subcategories on the left, the listing centred, sorting behind a
        disclosure on the right. The disclosure is a <details>, not a script:
        with JavaScript off it is a panel that opens, which is the whole
        difference between a drawer and a dead button.
    --}}
    <div class="lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:gap-12">
        <div class="hidden lg:block">
            <x-storefront::breadcrumbs :items="collect($ancestors)
                ->map(fn ($parent) => ['label' => $parent->name, 'url' => $parent->url()])
                ->push(['label' => $category->name, 'url' => $category->url()])
                ->prepend(['label' => 'Úvod', 'url' => '/'])
                ->all()" />

            @if ($children->isNotEmpty())
                <nav aria-label="Podkategorie">
                    <h2 class="mb-3 text-xs uppercase tracking-[0.16em] text-ink">{{ $category->name }}</h2>
                    <ul class="space-y-2 text-sm">
                        @foreach ($children as $child)
                            <li>
                                <a href="{{ $child->url() }}" class="text-ink-muted hover:text-ink hover:underline">
                                    {{ $child->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </div>

        <div>
            <div class="mb-8 flex flex-wrap items-baseline justify-between gap-4">
                <h1 class="text-2xl sm:text-3xl">
                    {{ $category->name }}
                    <span class="ml-2 text-sm font-normal normal-case tracking-normal text-ink-muted">
                        <x-storefront::product-count :count="$products->total()" />
                    </span>
                </h1>

                @if ($products->total() > 0)
                    <details class="relative">
                        <summary class="cursor-pointer list-none border border-line px-4 py-2 text-sm uppercase tracking-[0.12em] text-ink">
                            Filtrovat / řadit
                        </summary>
                        {{-- Static in the flow on a phone, floated on a wide
                             screen: a panel positioned over a narrow viewport
                             covers the very listing it filters. --}}
                        <div class="mt-3 border border-line bg-surface p-4 sm:absolute sm:right-0 sm:z-20 sm:w-80">
                            <x-storefront::sort-form :query="$query" />
                        </div>
                    </details>
                @endif
            </div>

            <div class="lg:hidden">
                @if ($children->isNotEmpty())
                    <nav aria-label="Podkategorie" class="mb-8">
                        <ul class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                            @foreach ($children as $child)
                                <li>
                                    <a href="{{ $child->url() }}" class="uppercase tracking-[0.1em] text-ink-muted hover:underline">
                                        {{ $child->name }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                @endif
            </div>

            @if ($category->description_above)
                {{-- Sanitised on write by HtmlSanitizer; sanitising again here
                     would put the policy in two places. --}}
                <div class="prose-shop mb-8 max-w-2xl">{!! $category->description_above !!}</div>
            @endif

            @if ($products->total() === 0)
                <p class="text-ink-muted">V této kategorii zatím nic nenabízíme.</p>
            @else
                <x-storefront::product-grid :products="$products" />

                <div class="mt-12">
                    {{ $products->links() }}
                </div>
            @endif

            @if ($category->description_below)
                <div class="prose-shop mt-16 max-w-2xl border-t border-line pt-8">{!! $category->description_below !!}</div>
            @endif
        </div>
    </div>
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
