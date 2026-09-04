@extends('storefront::layouts.shop')

@section('content')
    <x-storefront::breadcrumbs :items="array_values(array_filter([
        ['label' => 'Úvod', 'url' => '/'],
        $category ? ['label' => $category->name, 'url' => $category->url()] : null,
        ['label' => $product->name, 'url' => $product->url()],
    ]))" />

    <div class="grid gap-12 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        {{--
            Gallery: a vertical rail of thumbnails beside one tall image. The
            server renders the main image and every thumbnail is a real link to
            its own file, so with JavaScript off the customer still reaches all
            of them; the island only swaps the main image in place.
        --}}
        <div data-gallery class="flex gap-4">
            @if ($images->count() > 1)
                <ul class="hidden w-20 shrink-0 flex-col gap-3 sm:flex">
                    @foreach ($images as $image)
                        @php $url = app(\App\Core\Storage\FileStorage::class)->publicUrl($image->path); @endphp
                        <li>
                            <a href="{{ $url }}" data-gallery-thumb="{{ $url }}"
                               class="block focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2">
                                <img src="{{ $url }}" alt="{{ $image->alt ?: $product->name }}"
                                     class="aspect-[3/4] w-full object-cover" loading="lazy">
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="min-w-0 flex-1">
                @php $main = $product->mainImage(); @endphp

                @if ($main)
                    {{-- The page's largest element: eager and prioritised,
                         because this is the LCP on a product page. --}}
                    <img data-gallery-main
                         src="{{ app(\App\Core\Storage\FileStorage::class)->publicUrl($main->path) }}"
                         alt="{{ $main->alt ?: $product->name }}"
                         fetchpriority="high"
                         class="aspect-[3/4] w-full object-cover">
                @else
                    {{-- Decorative: an empty alt keeps it out of the
                         accessibility tree instead of announcing "no image". --}}
                    <div class="aspect-[3/4] w-full bg-surface-muted" aria-hidden="true"></div>
                @endif

                @if ($images->count() > 1)
                    <ul class="mt-3 flex gap-2 sm:hidden">
                        @foreach ($images as $image)
                            @php $url = app(\App\Core\Storage\FileStorage::class)->publicUrl($image->path); @endphp
                            <li>
                                <a href="{{ $url }}" data-gallery-thumb="{{ $url }}" class="block">
                                    <img src="{{ $url }}" alt="{{ $image->alt ?: $product->name }}"
                                         class="h-16 w-16 object-cover" loading="lazy">
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="lg:pt-6">
            <h1 class="text-2xl sm:text-3xl">{{ $product->name }}</h1>

            @if ($product->sku)
                <p class="mt-2 text-xs uppercase tracking-[0.14em] text-ink-muted">Kód: {{ $product->sku }}</p>
            @endif

            @if ($product->short_description)
                <p class="mt-5 text-ink-muted">{{ $product->short_description }}</p>
            @endif

            @php
                // Pricing, availability and the Omnibus reference are business
                // rules, not decoration: kept identical to the base view on
                // purpose. A theme decides where the price sits, never what it
                // says.
                $hasVariants = $product->catalogHasVariants();
                $selectedVariant = $hasVariants ? $variants->first(fn ($v) => $v->catalogVariantIsAvailable()) : null;
                $preselected = $selectedVariant?->catalogVariantSelection() ?? [];
                $displayPrice = $selectedVariant?->catalogVariantPrice() ?? $product->catalogPrice();
                $vatApplies = app(\App\Core\Tax\VatMode::class)->appliesVat();
                $displayNetPrice = $vatApplies ? $product->rate()->net($displayPrice) : null;
                $isAvailable = $product->catalogIsAvailable();
                $onSale = $selectedVariant !== null
                    ? $selectedVariant->catalogVariantIsOnSale()
                    : $product->catalogIsOnSale();
                $regularPrice = $selectedVariant?->catalogVariantRegularPrice() ?? $product->catalogRegularPrice();
                $lowestPrice = $onSale ? $product->catalogLowestPriceIn30Days() : null;
                $salePercent = $lowestPrice !== null && $lowestPrice->amount > $displayPrice->amount
                    ? (int) round(($lowestPrice->amount - $displayPrice->amount) / $lowestPrice->amount * 100)
                    : null;
            @endphp

            <p class="mt-6">
                <span class="text-2xl font-semibold {{ $onSale ? 'text-red-700' : 'text-ink' }}" data-variant-price>{{ $displayPrice->format() }}</span>

                @if ($onSale)
                    <s class="ml-2 text-base text-ink-muted" data-variant-regular-price>
                        <span class="sr-only">Původní cena</span>{{ $regularPrice->format() }}
                    </s>

                    @if ($salePercent !== null)
                        <span class="badge ml-2 bg-red-100 text-red-800" data-sale-badge>−{{ $salePercent }} %</span>
                    @endif
                @endif

                @if ($vatApplies)
                    <span class="mt-1 block text-sm text-ink-muted">
                        s DPH · bez DPH <span data-variant-net-price>{{ $displayNetPrice->format() }}</span>
                    </span>
                @endif

                {{-- § 12a of the consumer protection act: the reference price
                     goes with the discount, never separately from it. --}}
                @if ($lowestPrice !== null)
                    <span class="block text-sm text-ink-muted" data-variant-lowest-price>
                        Nejnižší cena za posledních 30 dní: {{ $lowestPrice->format() }}
                    </span>
                @endif
            </p>

            <p class="mt-4">
                @if ($isAvailable)
                    <span class="badge bg-emerald-100 text-emerald-900">Skladem</span>
                @else
                    <span class="badge bg-amber-100 text-amber-900">Vyprodáno</span>
                @endif
            </p>

            @if ($cartEnabled && $isAvailable)
                {{--
                    A real form. This POST and CartController's redirect are
                    the whole "add to cart" interaction; an island may make it
                    reload-free later, but it must never be the only way it
                    works (.claude/rules/storefront-rendering.md).
                --}}
                <form method="POST" action="{{ route('storefront.checkout.add') }}" class="mt-8">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">

                    @if ($hasVariants)
                        @include('products::storefront.partials.variant-picker', [
                            'product' => $product,
                            'options' => $options,
                            'variants' => $variants,
                            'preselected' => $preselected,
                        ])
                    @endif

                    <x-products::addons :groups="$addonGroups" />

                    <div class="mt-6 flex items-end gap-3">
                        <div>
                            <label for="mnozstvi" class="field-label">Množství</label>
                            <input id="mnozstvi" name="quantity" type="number" value="1" min="1" max="99"
                                   inputmode="numeric" class="field-input w-20">
                        </div>

                        <button type="submit"
                                class="flex-1 bg-ink px-8 py-3 text-sm font-medium uppercase tracking-[0.16em] text-surface hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink focus-visible:ring-offset-2">
                            Přidat do košíku
                        </button>
                    </div>
                </form>
            @elseif ($cartEnabled)
                <p class="mt-8 text-sm text-ink-muted">
                    Produkt je momentálně vyprodaný. Pro dotaz nás kontaktujte.
                </p>
            @else
                <p class="mt-8 text-sm text-ink-muted">
                    Objednávky spustíme brzy. Pro dotaz k produktu nás kontaktujte.
                </p>
            @endif

            @if ($product->hasDimensions() || $product->weight_g > 0)
                <section class="mt-10 border-t border-line pt-8" aria-labelledby="nadpis-parametry">
                    <h2 id="nadpis-parametry" class="text-sm">Parametry</h2>

                    <dl class="mt-4 divide-y divide-line text-sm">
                        @if ($product->hasDimensions())
                            <div class="flex justify-between gap-4 py-2">
                                <dt class="text-ink-muted">Rozměry (d × š × v)</dt>
                                <dd>{{ $product->dimensionsLabel() }}</dd>
                            </div>
                        @endif

                        @if ($product->weight_g > 0)
                            <div class="flex justify-between gap-4 py-2">
                                <dt class="text-ink-muted">Hmotnost</dt>
                                <dd>{{ number_format($product->weight_g / 1000, 2, ',', ' ') }} kg</dd>
                            </div>
                        @endif
                    </dl>
                </section>
            @endif
        </div>
    </div>

    @if ($product->description)
        <section class="prose-shop mx-auto mt-20 max-w-2xl border-t border-line pt-10" aria-labelledby="nadpis-popis">
            <h2 id="nadpis-popis" class="text-sm">Popis</h2>
            <div class="mt-4">
                {{-- Sanitised on write (HtmlSanitizer), rendered as stored. --}}
                {!! $product->description !!}
            </div>
        </section>
    @endif
@endsection

@push('head')
    @php
        // Structured data is a contract with crawlers, not styling: identical
        // to the base view, including quoting the effective price rather than
        // the shelf one.
        $offers = $product->catalogHasVariants()
            ? $variants->map(fn ($variant) => [
                '@type' => 'Offer',
                'url' => url($product->url()),
                'sku' => $variant->catalogVariantSku(),
                'price' => number_format($variant->catalogVariantPrice()->amount / 100, 2, '.', ''),
                'priceCurrency' => $variant->catalogVariantPrice()->currency,
                'availability' => $variant->catalogVariantIsAvailable()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ])->values()->all()
            : [
                '@type' => 'Offer',
                'url' => url($product->url()),
                'price' => number_format($product->catalogPrice()->amount / 100, 2, '.', ''),
                'priceCurrency' => $product->price->currency,
                'availability' => $product->isAvailable()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ];
    @endphp

    <x-storefront::json-ld :data="array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        'description' => $product->seo_description ?: $product->short_description,
        'sku' => $product->sku,
        'gtin13' => $product->ean && strlen($product->ean) === 13 ? $product->ean : null,
        'image' => $seo->image,
        'offers' => $offers,
    ])" />
@endpush
