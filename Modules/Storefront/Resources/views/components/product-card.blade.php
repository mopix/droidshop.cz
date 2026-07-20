@props(['product'])

<article class="flex h-full flex-col rounded border border-slate-200 p-4">
    <a href="{{ $product->catalogUrl() }}" class="block focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900">
        @if ($product->catalogImageUrl())
            <img src="{{ $product->catalogImageUrl() }}"
                 alt="{{ $product->catalogImageAlt() ?: $product->catalogName() }}"
                 class="mb-3 aspect-square w-full rounded object-cover"
                 loading="lazy" decoding="async">
        @else
            {{-- Decorative placeholder: an empty alt keeps it out of the
                 accessibility tree instead of announcing "no image". --}}
            <div class="mb-3 aspect-square w-full rounded bg-slate-100" aria-hidden="true"></div>
        @endif

        <h3 class="font-medium">{{ $product->catalogName() }}</h3>
    </a>

    @if ($product->catalogShortDescription())
        <p class="mt-1 text-sm text-slate-600">{{ Str::limit($product->catalogShortDescription(), 90) }}</p>
    @endif

    <p class="mt-auto pt-3">
        <span class="text-lg font-semibold">{{ $product->catalogPrice()->format() }}</span>
        <span class="block text-xs text-slate-500">s DPH</span>
    </p>

    @unless ($product->catalogIsAvailable())
        <p class="mt-1 text-sm text-amber-700">Vyprodáno</p>
    @endunless
</article>
