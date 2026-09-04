{{--
    Every slide is in the HTML and the track scrolls, so with no JavaScript the
    visitor can still reach all of them — the dots are anchors, not buttons
    bound to a script. An island may later add arrows and autoplay on top; it
    must never become the only way the thing works.
--}}
<section class="mb-10" aria-roledescription="carousel" aria-label="Doporučujeme">
    <div class="flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth" data-slider-track>
        @foreach ($slides as $index => $slide)
            <div id="slide-{{ $id }}-{{ $index }}"
                 class="w-full flex-none snap-start overflow-hidden rounded-token-lg bg-surface-muted"
                 role="group"
                 aria-roledescription="slide"
                 aria-label="{{ $index + 1 }} z {{ count($slides) }}">
                <div class="grid items-stretch {{ !empty($slide['image_path']) ? 'md:grid-cols-2' : '' }}">
                    <div class="flex flex-col justify-center p-8 md:p-12">
                        <h2 class="text-2xl sm:text-3xl">{{ $slide['title'] ?? '' }}</h2>

                        @if (!empty($slide['subtitle']))
                            <p class="mt-3 text-ink-muted">{{ $slide['subtitle'] }}</p>
                        @endif

                        @if (!empty($slide['cta_label']) && !empty($slide['cta_url']))
                            <a href="{{ $slide['cta_url'] }}" class="btn btn-primary mt-6 self-start px-6 py-3">
                                {{ $slide['cta_label'] }}
                            </a>
                        @endif
                    </div>

                    @if (!empty($slide['image_path']))
                        {{-- The first slide is the page's LCP on most visits;
                             the rest are below the fold of the track. --}}
                        <img src="{{ app(\App\Core\Storage\FileStorage::class)->publicUrl($slide['image_path']) }}"
                             alt="{{ $slide['alt'] ?? '' }}"
                             loading="{{ $index === 0 ? 'eager' : 'lazy' }}"
                             @if ($index === 0) fetchpriority="high" @endif
                             class="h-full w-full object-cover">
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @if (count($slides) > 1)
        <nav class="mt-4 flex justify-center gap-2" aria-label="Slidy">
            @foreach ($slides as $index => $slide)
                <a href="#slide-{{ $id }}-{{ $index }}"
                   class="h-2.5 w-2.5 rounded-full bg-line hover:bg-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                   data-slider-dot="{{ $index }}">
                    <span class="sr-only">Slide {{ $index + 1 }}</span>
                </a>
            @endforeach
        </nav>
    @endif
</section>
