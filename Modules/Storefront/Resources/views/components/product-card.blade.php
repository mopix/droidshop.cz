@props(['product'])

<article class="card flex h-full flex-col overflow-hidden p-4 transition hover:shadow-md">
    <a href="{{ $product->catalogUrl() }}"
       class="block rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2">
        @if ($product->catalogImageUrl())
            <img src="{{ $product->catalogImageUrl() }}"
                 alt="{{ $product->catalogImageAlt() ?: $product->catalogName() }}"
                 class="mb-3 aspect-square w-full rounded-lg object-cover"
                 loading="lazy" decoding="async">
        @else
            {{-- Decorative placeholder: an empty alt keeps it out of the
                 accessibility tree instead of announcing "no image". --}}
            <div class="mb-3 aspect-square w-full rounded-lg bg-slate-100" aria-hidden="true"></div>
        @endif

        <h3 class="font-medium text-slate-900">{{ $product->catalogName() }}</h3>
    </a>

    @if ($product->catalogShortDescription())
        <p class="mt-1 text-sm text-slate-600">{{ Str::limit($product->catalogShortDescription(), 90) }}</p>
    @endif

    <div class="mt-auto flex items-end justify-between gap-2 pt-3">
        <p>
            @if ($product->catalogHasVariants())
                <span class="text-sm text-slate-500">od</span>
            @endif

            <span class="text-lg font-semibold {{ $product->catalogIsOnSale() ? 'text-red-700' : 'text-slate-900' }}">
                {{ $product->catalogHasVariants() ? $product->catalogPriceFrom()->format() : $product->catalogPrice()->format() }}
            </span>

            {{-- The struck-through nominal price only; the statutory 30-day
                 line lives on the detail page, where the announcement of the
                 discount is made in full. --}}
            @if ($product->catalogIsOnSale())
                <s class="ml-1 text-sm text-slate-500">{{ $product->catalogRegularPrice()->format() }}</s>
            @endif

            {{-- A shop that is not registered for VAT says nothing about it
                 (wave 3.7). --}}
            @if (app(\App\Core\Tax\VatMode::class)->appliesVat())
                <span class="block text-xs text-slate-500">s DPH</span>
            @endif
        </p>

        <a href="{{ $product->catalogUrl() }}" class="btn btn-outline">Detail</a>
    </div>

    @unless ($product->catalogIsAvailable())
        <p class="badge mt-2 self-start bg-amber-100 text-amber-800">Vyprodáno</p>
    @endunless
</article>
