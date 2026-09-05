@props(['query'])

{{-- Sorting and filtering are a plain GET form: the storefront rule requires
     the catalogue to work with JavaScript switched off, so the server reads
     the query string and JS may only enhance the submit. --}}
<form method="get" class="mb-6 flex flex-wrap items-end gap-4" data-storefront-autosubmit>
    {{-- Only the whitelisted, normalised parameters are carried over, never
         request()->except(...). This form is rendered into a page that is
         cached and shared between anonymous visitors, so echoing the raw
         query here would store the first visitor's mc_eid / gclid / fbclid in
         a hidden field handed to everyone after them — the same leak as
         withQueryString() on the paginator, and this one also re-emitted the
         raw, unfolded `q`. The set comes from PageCacheKey so it can never
         drift from what the cache key is built from. --}}
    @foreach (\App\Core\PageCache\PageCacheKey::whitelistedInputs(request()) as $key => $value)
        @continue(in_array($key, ['razeni', 'skladem', 'page', 'na-stranku'], true))
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endforeach

    <div>
        <label for="razeni" class="field-label">Řadit</label>
        <select id="razeni" name="razeni" class="field-input">
            <option value="nejnovejsi" @selected($query->sort === 'nejnovejsi')>Nejnovější</option>
            <option value="cena-asc" @selected($query->sort === 'cena-asc')>Nejlevnější</option>
            <option value="cena-desc" @selected($query->sort === 'cena-desc')>Nejdražší</option>
            <option value="nazev" @selected($query->sort === 'nazev')>Podle názvu</option>
        </select>
    </div>

    <div>
        <label for="na-stranku" class="field-label">Na stránku</label>
        <select id="na-stranku" name="na-stranku" class="field-input">
            @foreach (\App\Core\Catalog\ProductQuery::PER_PAGE as $size)
                <option value="{{ $size }}" @selected($query->perPage === $size)>{{ $size }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex items-center gap-2">
        <input id="skladem" name="skladem" type="checkbox" value="1" @checked($query->inStockOnly)
               class="h-4 w-4 rounded border-slate-300 text-brand focus:ring-brand">
        <label for="skladem" class="text-sm text-slate-700">Pouze skladem</label>
    </div>

    <button type="submit" class="btn btn-outline">Použít</button>
</form>
