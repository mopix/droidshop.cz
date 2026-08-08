{{--
    Server-rendered variant picker. Every axis and every value is in the HTML
    of the first response; the POST below carries option_value_id[axis_id]
    and the server decides which variant that is (CartController::add).

    The field name is keyed per axis — option_value_id[<axis id>] — not a
    shared option_value_id[] on every radio: HTML groups radios by form +
    name, not by <fieldset>, so a second axis sharing the same name would
    collapse into one mutually-exclusive group with the first and a
    multi-axis product could never be bought (a browser only ever submits
    the last-checked radio of the group). AddCartItemRequest and
    resolveVariant() both accept the resulting associative array unchanged.

    A future JS island may re-render the price without a reload, but it is
    never the thing that makes the form work
    (.claude/rules/storefront-rendering.md).
--}}
@foreach ($options as $option)
    @if ($product->catalogVariantDisplay() === 'select')
        <div class="mt-6">
            <label for="osa-{{ $option->id }}" class="field-label">{{ $option->name }}</label>
            <select id="osa-{{ $option->id }}"
                    name="option_value_id[{{ $option->id }}]"
                    data-variant-axis="{{ $option->id }}"
                    class="field-input max-w-xs"
                    required>
                @foreach ($option->values as $value)
                    <option value="{{ $value->id }}"
                            @selected(($preselected[$option->id] ?? null) === $value->id)>{{ $value->value }}</option>
                @endforeach
            </select>
        </div>
    @else
        {{-- fieldset/legend, not a bare label: a radio group needs a group
             name in the accessibility tree (WCAG 1.3.1). --}}
        <fieldset class="mt-6">
            <legend class="field-label">{{ $option->name }}</legend>

            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($option->values as $value)
                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2">
                        <input type="radio"
                               name="option_value_id[{{ $option->id }}]"
                               value="{{ $value->id }}"
                               data-variant-axis="{{ $option->id }}"
                               @checked(($preselected[$option->id] ?? null) === $value->id)
                               required>
                        <span>{{ $value->value }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endif
@endforeach

@error('option_value_id')
    <p class="mt-3 text-sm text-red-700">{{ $message }}</p>
@enderror

{{--
    The island's data source. Read-only JSON, no behaviour depends on it.
    net_price is pre-formatted server-side (same TaxRate conversion as the
    initial render below) so the island never does price arithmetic in JS —
    it only ever displays a string the server already computed.
--}}
@php
    // A shop that is not registered for VAT has no net price to show, so the
    // island gets none and simply leaves that element alone (wave 3.7).
    $vatApplies = app(\App\Core\Tax\VatMode::class)->appliesVat();

    $variantMatrix = $variants->map(function ($variant) use ($product, $vatApplies) {
        return [
            'id' => $variant->getKey(),
            'selection' => array_values($variant->catalogVariantSelection()),
            'price' => $variant->catalogVariantPrice()->format(),
            'net_price' => $vatApplies
                ? $product->rate()->net($variant->catalogVariantPrice())->format()
                : null,
            'regular_price' => $variant->catalogVariantRegularPrice()->format(),
            'on_sale' => $variant->catalogVariantIsOnSale(),
            'available' => $variant->catalogVariantIsAvailable(),
        ];
    })->values();
@endphp
<script type="application/json" data-variant-matrix>@json($variantMatrix)</script>
