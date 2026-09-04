{{--
    The split band: picture on one side, words on the other, edge to edge.
    Text first in the source so a screen reader and a crawler meet the message
    before the decoration.
--}}
@php($hasImage = ! empty($image_path))

{{-- Two columns only when there is a picture to fill the second one. A shop
     that has not uploaded a hero image would otherwise get a coloured panel
     across half the window and nothing beside it. --}}
<section class="bleed mb-12">
    <div class="grid items-stretch {{ $hasImage ? 'md:grid-cols-2' : '' }}">
        <div class="flex flex-col justify-center bg-surface-muted px-8 py-16 text-center md:px-16">
            <h1 class="text-3xl sm:text-4xl">{{ $title ?? '' }}</h1>

            @if (!empty($subtitle))
                <p class="mx-auto mt-4 max-w-md text-ink-muted">{{ $subtitle }}</p>
            @endif

            @if (!empty($cta_label) && !empty($cta_url))
                <a href="{{ $cta_url }}"
                   class="mx-auto mt-8 inline-block bg-ink px-8 py-3 text-sm font-medium uppercase tracking-[0.16em] text-surface hover:opacity-90">
                    {{ $cta_label }}
                </a>
            @endif
        </div>

        @if ($hasImage)
            {{-- The largest element above the fold: eager, with a priority
                 hint, because this is the shop's LCP on most visits. --}}
            <img src="{{ app(\App\Core\Storage\FileStorage::class)->publicUrl($image_path) }}"
                 alt="{{ $alt ?? '' }}" loading="eager" fetchpriority="high"
                 class="h-full w-full object-cover">
        @endif
    </div>
</section>
