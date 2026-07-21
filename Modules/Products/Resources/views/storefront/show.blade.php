@extends('storefront::layouts.shop')

@section('content')
    <x-storefront::breadcrumbs :items="array_values(array_filter([
        ['label' => 'Úvod', 'url' => '/'],
        $category ? ['label' => $category->name, 'url' => $category->url()] : null,
        ['label' => $product->name, 'url' => $product->url()],
    ]))" />

    <div class="grid gap-8 lg:grid-cols-2">
        {{-- Gallery: the main image is rendered by the server; the thumbnails
             only swap it once JavaScript is there. Without JS the customer
             still sees every image. --}}
        <div data-gallery>
            @php $main = $product->mainImage(); @endphp

            @if ($main)
                <img data-gallery-main
                     src="{{ app(\App\Core\Storage\FileStorage::class)->publicUrl($main->path) }}"
                     alt="{{ $main->alt ?: $product->name }}"
                     class="w-full rounded border border-slate-200 object-cover">
            @endif

            @if ($images->count() > 1)
                <ul class="mt-3 flex flex-wrap gap-3">
                    @foreach ($images as $image)
                        @php $url = app(\App\Core\Storage\FileStorage::class)->publicUrl($image->path); @endphp
                        <li>
                            <a href="{{ $url }}" data-gallery-thumb="{{ $url }}"
                               class="block rounded border border-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900">
                                <img src="{{ $url }}" alt="{{ $image->alt ?: $product->name }}"
                                     class="h-20 w-20 rounded object-cover" loading="lazy">
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div>
            <h1 class="text-2xl font-semibold">{{ $product->name }}</h1>

            @if ($product->sku)
                <p class="mt-1 text-sm text-slate-500">Kód: {{ $product->sku }}</p>
            @endif

            @if ($product->short_description)
                <p class="mt-4">{{ $product->short_description }}</p>
            @endif

            <p class="mt-6">
                <span class="text-3xl font-semibold">{{ $product->price->format() }}</span>
                <span class="block text-sm text-slate-500">
                    s DPH · bez DPH {{ $product->netPrice()->format() }}
                </span>
            </p>

            <p class="mt-4">
                @if ($product->isAvailable())
                    <span class="rounded bg-emerald-50 px-3 py-1 text-emerald-800">Skladem</span>
                @else
                    <span class="rounded bg-amber-50 px-3 py-1 text-amber-800">Vyprodáno</span>
                @endif
            </p>

            @if ($cartEnabled && $product->isAvailable())
                {{--
                    A real HTML form, no JS: this POST plus the redirect back
                    from CartController::add is the entire "add to cart"
                    interaction — an Alpine/Vue island may enhance it later
                    (no reload), but it must never be the only way it works
                    (.claude/rules/storefront-rendering.md).
                --}}
                <form method="POST" action="{{ route('storefront.checkout.add') }}" class="mt-6 flex items-end gap-3">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">

                    <div>
                        <label for="mnozstvi" class="block text-sm text-slate-600">Množství</label>
                        <input id="mnozstvi" name="quantity" type="number" value="1" min="1" max="99"
                               inputmode="numeric" class="mt-1 w-20 rounded border border-slate-300 px-2 py-2">
                    </div>

                    <button type="submit" class="rounded bg-slate-900 px-6 py-2 text-white">
                        Přidat do košíku
                    </button>
                </form>
            @elseif ($cartEnabled)
                <p class="mt-6 text-sm text-slate-600">
                    Produkt je momentálně vyprodaný. Pro dotaz nás kontaktujte.
                </p>
            @else
                <p class="mt-6 text-sm text-slate-600">
                    Objednávky spustíme brzy. Pro dotaz k produktu nás kontaktujte.
                </p>
            @endif
        </div>
    </div>

    @if ($product->description)
        <section class="prose mt-12 max-w-none" aria-labelledby="nadpis-popis">
            <h2 id="nadpis-popis">Popis</h2>
            {{-- Sanitised on write (HtmlSanitizer), rendered as stored. --}}
            {!! $product->description !!}
        </section>
    @endif
@endsection

@push('head')
    <x-storefront::json-ld :data="array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        'description' => $product->seo_description ?: $product->short_description,
        'sku' => $product->sku,
        'gtin13' => $product->ean && strlen($product->ean) === 13 ? $product->ean : null,
        'image' => $seo->image,
        'offers' => [
            '@type' => 'Offer',
            'url' => url($product->url()),
            'price' => number_format($product->price->amount / 100, 2, '.', ''),
            'priceCurrency' => $product->price->currency,
            'availability' => $product->isAvailable()
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
        ],
    ])" />
@endpush
