@props(['product'])

{{--
    Editorial card: the photograph is the card. No border, no shadow, no
    padding — the tiles sit against each other and the picture carries the
    tile, which is what makes a grid of them read as a lookbook rather than as
    a spreadsheet.
--}}
<article class="group flex h-full flex-col">
    <a href="{{ $product->catalogUrl() }}"
       class="block focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2">
        @if ($product->catalogImageUrl())
            <img src="{{ $product->catalogImageUrl() }}"
                 alt="{{ $product->catalogImageAlt() ?: $product->catalogName() }}"
                 class="aspect-[3/4] w-full object-cover"
                 loading="lazy" decoding="async">
        @else
            {{-- Decorative: an empty alt keeps it out of the accessibility
                 tree instead of announcing "no image". --}}
            <div class="aspect-[3/4] w-full bg-surface-muted" aria-hidden="true"></div>
        @endif

        <h3 class="mt-3 text-sm text-ink group-hover:underline">{{ $product->catalogName() }}</h3>
    </a>

    <p class="mt-1">
        @if ($product->catalogHasVariants())
            <span class="text-sm text-ink-muted">od</span>
        @endif

        <span class="text-base font-semibold {{ $product->catalogIsOnSale() ? 'text-red-700' : 'text-ink' }}">
            {{ $product->catalogHasVariants() ? $product->catalogPriceFrom()->format() : $product->catalogPrice()->format() }}
        </span>

        @if ($product->catalogIsOnSale())
            {{--
                The old price is struck through for the eye and named for the
                screen reader: <s> alone announces nothing, and a discount
                signalled by colour and a line only would be information
                carried by presentation (WCAG 1.4.1).
            --}}
            <s class="ml-1 text-sm text-ink-muted">
                <span class="sr-only">Původní cena</span>{{ $product->catalogRegularPrice()->format() }}
            </s>
            <span class="sr-only">Zlevněno</span>
        @endif

        {{-- A shop not registered for VAT says nothing about it (wave 3.7). --}}
        @if (app(\App\Core\Tax\VatMode::class)->appliesVat())
            <span class="ml-1 text-xs text-ink-muted">s DPH</span>
        @endif
    </p>

    @unless ($product->catalogIsAvailable())
        <p class="badge mt-2 self-start bg-ink px-2 text-surface">Vyprodáno</p>
    @endunless
</article>
