@props(['product'])

{{--
    Catalogue card: bordered, white against the page's grey, with availability,
    price and a way into the detail. A shopper comparing rows wants the facts on
    the tile rather than a mood.
--}}
<article class="card flex h-full flex-col overflow-hidden p-4 transition hover:border-brand">
    <a href="{{ $product->catalogUrl() }}"
       class="block rounded-token focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2">
        @if ($product->catalogImageUrl())
            <img src="{{ $product->catalogImageUrl() }}"
                 alt="{{ $product->catalogImageAlt() ?: $product->catalogName() }}"
                 class="mb-3 aspect-square w-full rounded-token object-cover"
                 loading="lazy" decoding="async">
        @else
            {{-- Decorative: an empty alt keeps it out of the accessibility
                 tree instead of announcing "no image". --}}
            <div class="mb-3 aspect-square w-full rounded-token bg-surface-muted" aria-hidden="true"></div>
        @endif

        <h3 class="font-semibold text-ink">{{ $product->catalogName() }}</h3>
    </a>

    @if ($product->catalogShortDescription())
        <p class="mt-1 text-sm text-ink-muted">{{ Str::limit($product->catalogShortDescription(), 90) }}</p>
    @endif

    <p class="mt-2 text-sm font-medium {{ $product->catalogIsAvailable() ? 'text-emerald-700' : 'text-amber-800' }}">
        {{ $product->catalogIsAvailable() ? 'Skladem' : 'Vyprodáno' }}
    </p>

    <div class="mt-auto flex items-end justify-between gap-2 pt-3">
        <p>
            @if ($product->catalogHasVariants())
                <span class="text-sm text-ink-muted">od</span>
            @endif

            <span class="text-lg font-bold {{ $product->catalogIsOnSale() ? 'text-red-700' : 'text-ink' }}">
                {{ $product->catalogHasVariants() ? $product->catalogPriceFrom()->format() : $product->catalogPrice()->format() }}
            </span>

            @if ($product->catalogIsOnSale())
                {{-- Struck through for the eye, named for the screen reader:
                     a discount signalled by colour and a line alone is
                     information carried by presentation (WCAG 1.4.1). The
                     statutory 30-day reference stays on the detail page, where
                     the discount is announced in full. --}}
                <s class="ml-1 text-sm text-ink-muted">
                    <span class="sr-only">Původní cena</span>{{ $product->catalogRegularPrice()->format() }}
                </s>
                <span class="badge ml-1 bg-red-100 text-red-800">Sleva</span>
            @endif

            {{-- A shop not registered for VAT says nothing about it (wave 3.7). --}}
            @if (app(\App\Core\Tax\VatMode::class)->appliesVat())
                <span class="block text-xs text-ink-muted">s DPH</span>
            @endif
        </p>

        <a href="{{ $product->catalogUrl() }}"
           class="btn shrink-0 bg-brand text-brand-contrast hover:opacity-90">Detail</a>
    </div>
</article>
