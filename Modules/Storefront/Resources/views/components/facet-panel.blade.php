@props(['facets', 'query'])

@if (count($facets) > 0)
    {{--
        A plain GET form with checkboxes. An island may submit it on change;
        without JavaScript the button is what applies the filter, which is the
        whole difference between a filter and a decorative sidebar.

        Values are carried as `vlastnost[kod][]` and normalised on the server —
        sorted and de-duplicated — so two orderings of the same choice are one
        page-cache entry rather than two copies of identical HTML.
    --}}
    <form method="get" class="card p-4" aria-label="Filtry">
        {{-- Only whitelisted, normalised parameters travel along. Echoing the
             raw query here would store one visitor's tracking parameters in a
             hidden field handed to everyone the cached page reaches. --}}
        @foreach (\App\Core\PageCache\PageCacheKey::whitelistedInputs(request()) as $key => $value)
            @continue(in_array($key, ['vlastnost', 'page'], true))
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach

        @foreach ($facets as $facet)
            <fieldset class="mb-4 border-b border-line pb-4 last:mb-0 last:border-0 last:pb-0">
                <legend class="text-sm font-semibold text-ink">{{ $facet->name }}</legend>

                <ul class="mt-2 space-y-1">
                    @foreach ($facet->values as $value)
                        <li class="flex items-center gap-2">
                            <input
                                id="facet-{{ $facet->code }}-{{ $value['slug'] }}"
                                type="checkbox"
                                name="vlastnost[{{ $facet->code }}][]"
                                value="{{ $value['slug'] }}"
                                @checked($value['selected'])
                                @disabled($value['count'] === 0 && ! $value['selected'])
                                class="h-4 w-4 rounded border-line text-brand focus:ring-brand">
                            <label for="facet-{{ $facet->code }}-{{ $value['slug'] }}"
                                   class="text-sm {{ $value['count'] === 0 && ! $value['selected'] ? 'text-ink-muted' : 'text-ink' }}">
                                {{ $value['label'] }}
                                <span class="text-ink-muted">({{ $value['count'] }})</span>
                            </label>
                        </li>
                    @endforeach
                </ul>
            </fieldset>
        @endforeach

        <div class="mt-4 flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">Filtrovat</button>

            @if ($query->attributes !== [])
                {{-- A link, not a reset button: clearing filters is navigation
                     to the plain shelf, and it has to work with the form
                     untouched. --}}
                <a href="{{ url()->current() }}" class="btn btn-outline">Zrušit filtry</a>
            @endif
        </div>
    </form>
@endif
