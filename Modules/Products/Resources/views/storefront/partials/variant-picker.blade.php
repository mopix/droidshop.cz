{{--
    Server-rendered variant picker. Every axis and every value is in the HTML
    of the first response; the POST below carries option_value_id[] and the
    server decides which variant that is (CartController::add). A future JS
    island may re-render the price without a reload, but it is never the
    thing that makes the form work (.claude/rules/storefront-rendering.md).
--}}
@foreach ($options as $option)
    @if ($product->catalogVariantDisplay() === 'select')
        <div class="mt-6">
            <label for="osa-{{ $option->id }}" class="field-label">{{ $option->name }}</label>
            <select id="osa-{{ $option->id }}"
                    name="option_value_id[]"
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
                               name="option_value_id[]"
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

{{-- The island's data source. Read-only JSON, no behaviour depends on it. --}}
@php
    $variantMatrix = $variants->map(function ($variant) {
        return [
            'id' => $variant->getKey(),
            'selection' => array_values($variant->catalogVariantSelection()),
            'price' => $variant->catalogVariantPrice()->format(),
            'available' => $variant->catalogVariantIsAvailable(),
        ];
    })->values();
@endphp
<script type="application/json" data-variant-matrix>@json($variantMatrix)</script>
