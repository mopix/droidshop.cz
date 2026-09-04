{{-- A contained card, not a full-width band: this theme keeps its page inside
     a frame, and a hero that escapes it reads as a different site. --}}
<section class="mb-10 overflow-hidden rounded-token-lg bg-surface shadow-sm">
    <div class="grid items-stretch md:grid-cols-2">
        <div class="flex flex-col justify-center p-8 md:p-12">
            <h1 class="text-2xl sm:text-3xl">{{ $title ?? '' }}</h1>

            @if (!empty($subtitle))
                <p class="mt-3 text-ink-muted">{{ $subtitle }}</p>
            @endif

            @if (!empty($cta_label) && !empty($cta_url))
                <a href="{{ $cta_url }}" class="btn btn-primary mt-6 self-start px-6 py-3">{{ $cta_label }}</a>
            @endif
        </div>

        @if (!empty($image_path))
            {{-- The LCP on most visits: eager and prioritised. --}}
            <img src="{{ app(\App\Core\Storage\FileStorage::class)->publicUrl($image_path) }}"
                 alt="{{ $alt ?? '' }}" loading="eager" fetchpriority="high"
                 class="h-full w-full object-cover">
        @endif
    </div>
</section>
