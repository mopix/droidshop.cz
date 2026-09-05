@extends('storefront::layouts.shop')

@section('content')
    <x-storefront::breadcrumbs :items="array_values(array_filter([
        ['label' => 'Úvod', 'url' => '/'],
        $category ? ['label' => $category->name, 'url' => $category->url()] : null,
        ['label' => $product->name, 'url' => $product->url()],
    ]))" />

    <div class="grid gap-8 lg:grid-cols-2">
        {{-- Gallery: the server renders the main image and every thumbnail is
             a real link to its own file, so with JavaScript off the customer
             still reaches all of them; the island only swaps in place. --}}
        <div data-gallery class="card p-4">
            @php $main = $product->mainImage(); @endphp

            @if ($main)
                <img data-gallery-main
                     src="{{ app(\App\Core\Storage\FileStorage::class)->publicUrl($main->path) }}"
                     alt="{{ $main->alt ?: $product->name }}"
                     fetchpriority="high"
                     class="aspect-square w-full rounded-token object-contain">
            @else
                {{-- Decorative: an empty alt keeps it out of the accessibility
                     tree instead of announcing "no image". --}}
                <div class="aspect-square w-full rounded-token bg-surface-muted" aria-hidden="true"></div>
            @endif

            @if ($images->count() > 1)
                <ul class="mt-4 grid grid-cols-5 gap-2">
                    @foreach ($images as $image)
                        @php $url = app(\App\Core\Storage\FileStorage::class)->publicUrl($image->path); @endphp
                        <li>
                            <a href="{{ $url }}" data-gallery-thumb="{{ $url }}"
                               class="block overflow-hidden rounded-token border border-line focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2">
                                <img src="{{ $url }}" alt="{{ $image->alt ?: $product->name }}"
                                     class="aspect-square w-full object-cover" loading="lazy">
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div>
            <h1 class="text-2xl sm:text-3xl">{{ $product->name }}</h1>

            @if ($product->sku)
                <p class="mt-1 text-sm text-ink-muted">Kód: {{ $product->sku }}</p>
            @endif

            @if ($product->short_description)
                <p class="mt-4 text-ink-muted">{{ $product->short_description }}</p>
            @endif

            @php
                // Pricing, availability and the Omnibus reference are business
                // rules, not decoration: identical to the base view on
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
                // The saving in korunas, from the SAME reference the percentage
                // uses — the 30-day low, not the shelf price. Two figures about
                // one discount must describe the same discount.
                $saving = $salePercent === null ? null : $lowestPrice->minus($displayPrice);
            @endphp

            <p class="mt-4 font-semibold {{ $isAvailable ? 'text-emerald-700' : 'text-amber-800' }}">
                {{ $isAvailable ? 'Skladem' : 'Vyprodáno' }}
            </p>

            <div class="card mt-4 p-5">
                <p>
                    <span class="text-3xl font-bold {{ $onSale ? 'text-red-700' : 'text-ink' }}" data-variant-price>{{ $displayPrice->format() }}</span>

                    @if ($onSale)
                        <s class="ml-2 text-lg text-ink-muted" data-variant-regular-price>
                            <span class="sr-only">Původní cena</span>{{ $regularPrice->format() }}
                        </s>

                        @if ($salePercent !== null)
                            <span class="badge ml-2 bg-red-100 text-red-800" data-sale-badge>−{{ $salePercent }} %</span>
                            <span class="ml-2 text-sm font-medium text-red-700" data-sale-saving>
                                Ušetříte {{ $saving->format() }}
                            </span>
                        @endif
                    @endif

                    @if ($vatApplies)
                        <span class="block text-sm text-ink-muted">
                            s DPH · bez DPH <span data-variant-net-price>{{ $displayNetPrice->format() }}</span>
                        </span>
                    @endif

                    {{-- § 12a of the consumer protection act: the reference
                         price travels with the discount, never apart from it. --}}
                    @if ($lowestPrice !== null)
                        <span class="block text-sm text-ink-muted" data-variant-lowest-price>
                            Nejnižší cena za posledních 30 dní: {{ $lowestPrice->format() }}
                        </span>
                    @endif
                </p>

                @if ($cartEnabled && $isAvailable)
                    {{--
                        A real form. This POST and CartController's redirect are
                        the whole "add to cart" interaction; an island may make
                        it reload-free later, but it must never be the only way
                        it works (.claude/rules/storefront-rendering.md).
                    --}}
                    <form method="POST" action="{{ route('storefront.checkout.add') }}" class="mt-5">
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

                        <div class="mt-5 flex items-end gap-3">
                            <div>
                                <label for="mnozstvi" class="field-label">Množství</label>
                                <input id="mnozstvi" name="quantity" type="number" value="1" min="1" max="99"
                                       inputmode="numeric" class="field-input w-20">
                            </div>

                            <button type="submit" class="btn btn-primary flex-1 px-6 py-3 text-base">
                                Vložit do košíku
                            </button>
                        </div>
                    </form>
                @elseif ($cartEnabled)
                    <p class="mt-5 text-sm text-ink-muted">
                        Produkt je momentálně vyprodaný. Pro dotaz nás kontaktujte.
                    </p>
                @else
                    <p class="mt-5 text-sm text-ink-muted">
                        Objednávky spustíme brzy. Pro dotaz k produktu nás kontaktujte.
                    </p>
                @endif
            </div>

            @if ($product->hasDimensions() || $product->weight_g > 0)
                <section class="card mt-5 p-5" aria-labelledby="nadpis-parametry">
                    <h2 id="nadpis-parametry" class="text-base">Parametry</h2>

                    <dl class="mt-3 divide-y divide-line text-sm">
                        @if ($product->hasDimensions())
                            <div class="flex justify-between gap-4 py-2">
                                <dt class="text-ink-muted">Rozměry (d × š × v)</dt>
                                <dd class="font-medium">{{ $product->dimensionsLabel() }}</dd>
                            </div>
                        @endif

                        @if ($product->weight_g > 0)
                            <div class="flex justify-between gap-4 py-2">
                                <dt class="text-ink-muted">Hmotnost</dt>
                                <dd class="font-medium">{{ number_format($product->weight_g / 1000, 2, ',', ' ') }} kg</dd>
                            </div>
                        @endif
                    </dl>
                </section>
            @endif
        </div>
    </div>

    @if ($product->description)
        <section class="card prose-shop mt-10 p-6" aria-labelledby="nadpis-popis">
            <h2 id="nadpis-popis" class="text-lg">Popis produktu</h2>
            <div class="mt-3">
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
