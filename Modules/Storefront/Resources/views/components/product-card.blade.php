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
            <span class="text-lg font-semibold text-slate-900">{{ $product->catalogPrice()->format() }}</span>
            <span class="block text-xs text-slate-500">s DPH</span>
        </p>

        <a href="{{ $product->catalogUrl() }}" class="btn btn-outline">Detail</a>
    </div>

    @unless ($product->catalogIsAvailable())
        <p class="badge mt-2 self-start bg-amber-100 text-amber-800">Vyprodáno</p>
    @endunless
</article>
