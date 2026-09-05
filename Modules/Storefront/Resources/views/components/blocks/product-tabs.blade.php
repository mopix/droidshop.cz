{{--
    Tabs as links, not as scripted buttons: without JavaScript the closed tabs
    still open, because each one is a real URL the server answers. `?zalozka=`
    is part of the page-cache key, so two tabs never share one stored page.

    Deliberately not role="tablist": that pattern promises arrow-key movement
    between panels rendered on the same page, and these are separate pages. A
    list of links is what this actually is.
--}}
<section class="mb-10" @if(!empty($heading)) aria-labelledby="tabs-heading-{{ $id }}" @endif>
    @if (!empty($heading))
        <h2 id="tabs-heading-{{ $id }}" class="mb-6 text-center text-xl">{{ $heading }}</h2>
    @endif

    <nav class="mb-6 flex flex-wrap justify-center gap-x-6 gap-y-2 border-b border-line" aria-label="Nabídka">
        @foreach ($tabs as $tab)
            <a href="?zalozka={{ $tab['number'] }}"
               @if ($tab['active']) aria-current="true" @endif
               class="-mb-px border-b-2 px-1 pb-3 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand
                      {{ $tab['active'] ? 'border-brand text-ink' : 'border-transparent text-ink-muted hover:text-ink' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>

    @foreach ($tabs as $tab)
        @continue(! $tab['active'])

        @if ($tab['products']->isEmpty())
            <p class="text-center text-ink-muted">Nabídka se právě připravuje.</p>
        @else
            <x-storefront::product-grid :products="$tab['products']" />
        @endif
    @endforeach
</section>
