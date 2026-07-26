@extends('storefront::layouts.shop')

@section('content')
    <x-storefront::breadcrumbs :items="array_values(array_filter([
        ['label' => 'Úvod', 'url' => '/'],
        $category ? ['label' => $category->name, 'url' => $category->url()] : null,
        ['label' => $product->name, 'url' => $product->url()],
    ]))" />

    <div class="grid gap-10 lg:grid-cols-2">
        {{-- Gallery: the main image is rendered by the server; the thumbnails
             only swap it once JavaScript is there. Without JS the customer
             still sees every image. --}}
        <div data-gallery>
            @php $main = $product->mainImage(); @endphp

            @if ($main)
                <img data-gallery-main
                     src="{{ app(\App\Core\Storage\FileStorage::class)->publicUrl($main->path) }}"
                     alt="{{ $main->alt ?: $product->name }}"
                     class="aspect-square w-full rounded-xl border border-slate-200 object-cover">
            @else
                {{-- Decorative placeholder: empty alt keeps it out of the
                     accessibility tree instead of announcing "no image". --}}
                <div class="aspect-square w-full rounded-xl bg-slate-100" aria-hidden="true"></div>
            @endif

            @if ($images->count() > 1)
                <ul class="mt-4 flex flex-wrap gap-3">
                    @foreach ($images as $image)
                        @php $url = app(\App\Core\Storage\FileStorage::class)->publicUrl($image->path); @endphp
                        <li>
                            <a href="{{ $url }}" data-gallery-thumb="{{ $url }}"
                               class="block overflow-hidden rounded-lg border border-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2">
                                <img src="{{ $url }}" alt="{{ $image->alt ?: $product->name }}"
                                     class="h-20 w-20 object-cover" loading="lazy">
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div>
            <h1 class="text-3xl font-semibold tracking-tight text-slate-900">{{ $product->name }}</h1>

            @if ($product->sku)
                <p class="mt-1 text-sm text-slate-500">Kód: {{ $product->sku }}</p>
            @endif

            @if ($product->short_description)
                <p class="mt-4 text-slate-600">{{ $product->short_description }}</p>
            @endif

            @php
                $hasVariants = $product->catalogHasVariants();
                $selectedVariant = $hasVariants ? $variants->first(fn ($v) => $v->catalogVariantIsAvailable()) : null;
                $preselected = $selectedVariant?->catalogVariantSelection() ?? [];
                $displayPrice = $selectedVariant?->catalogVariantPrice() ?? $product->price;
                // Same tax_rate_id either way (a variant never carries its own
                // VAT rate), so Product::rate() converts a variant's gross
                // price to net just as well as the product's own — the
                // conversion lives on TaxRate, never on Money itself.
                $displayNetPrice = $product->rate()->net($displayPrice);
                $isAvailable = $hasVariants
                    ? $variants->contains(fn ($v) => $v->catalogVariantIsAvailable())
                    : $product->isAvailable();
            @endphp

            <p class="mt-6">
                <span class="text-3xl font-semibold text-slate-900" data-variant-price>{{ $displayPrice->format() }}</span>
                <span class="block text-sm text-slate-500">
                    s DPH · bez DPH <span data-variant-net-price>{{ $displayNetPrice->format() }}</span>
                </span>
            </p>

            <p class="mt-4">
                @if ($isAvailable)
                    <span class="badge bg-emerald-100 text-emerald-800">Skladem</span>
                @else
                    <span class="badge bg-amber-100 text-amber-800">Vyprodáno</span>
                @endif
            </p>

            @if ($cartEnabled && $isAvailable)
                {{--
                    A real HTML form, no JS: this POST plus the redirect back
                    from CartController::add is the entire "add to cart"
                    interaction — an Alpine/Vue island may enhance it later
                    (no reload), but it must never be the only way it works
                    (.claude/rules/storefront-rendering.md).
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

                    <div class="mt-6 flex items-end gap-3">
                        <div>
                            <label for="mnozstvi" class="field-label">Množství</label>
                            <input id="mnozstvi" name="quantity" type="number" value="1" min="1" max="99"
                                   inputmode="numeric" class="field-input w-20">
                        </div>

                        <button type="submit" class="btn btn-primary">
                            Přidat do košíku
                        </button>
                    </div>
                </form>
            @elseif ($cartEnabled)
                <p class="mt-8 text-sm text-slate-600">
                    Produkt je momentálně vyprodaný. Pro dotaz nás kontaktujte.
                </p>
            @else
                <p class="mt-8 text-sm text-slate-600">
                    Objednávky spustíme brzy. Pro dotaz k produktu nás kontaktujte.
                </p>
            @endif
        </div>
    </div>

    @if ($product->description)
        <section class="prose-shop mt-14 border-t border-slate-100 pt-10" aria-labelledby="nadpis-popis">
            <h2 id="nadpis-popis" class="text-lg font-semibold text-slate-900">Popis</h2>
            <div class="mt-4">
                {{-- Sanitised on write (HtmlSanitizer), rendered as stored. --}}
                {!! $product->description !!}
            </div>
        </section>
    @endif
@endsection

@push('head')
    @php
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
                'price' => number_format($product->price->amount / 100, 2, '.', ''),
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
