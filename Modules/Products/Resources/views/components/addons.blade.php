@props(['groups'])

@if (count($groups) > 0)
    {{--
        Accessories, inside the add-to-cart form so they travel with it.

        Radios, not buttons: a group is one choice, and a choice a keyboard can
        move through with arrow keys is what a radio group already is. The
        prices are the server's; nothing here computes a total, and the figure
        the customer pays is recomputed when the cart is written
        (specifikace §16.3).
    --}}
    @foreach ($groups as $group)
        <fieldset class="mt-6">
            <legend class="field-label">
                {{ $group->label }}
                @if ($group->required)
                    <span aria-hidden="true">*</span>
                    <span class="sr-only">(povinné)</span>
                @endif
            </legend>

            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                @unless ($group->required)
                    {{-- "None" has to be expressible, or an optional group is
                         optional in name only once something is picked. --}}
                    <label class="card flex cursor-pointer flex-col gap-1 p-2 text-sm has-[:checked]:border-brand">
                        <input type="radio" name="addon_id[{{ $group->id }}]" value="" checked class="sr-only">
                        <span class="block aspect-[4/3] w-full rounded-token bg-surface-muted" aria-hidden="true"></span>
                        <span class="font-medium text-ink">Bez doplňku</span>
                        <span class="text-ink-muted">0 Kč</span>
                    </label>
                @endunless

                @foreach ($group->options as $option)
                    <label class="card flex cursor-pointer flex-col gap-1 p-2 text-sm has-[:checked]:border-brand">
                        <input
                            type="radio"
                            name="addon_id[{{ $group->id }}]"
                            value="{{ $option->id }}"
                            @if ($group->required && $loop->first) checked @endif
                            @if ($group->required) required @endif
                            class="sr-only">

                        @if ($option->imageUrl)
                            <img src="{{ $option->imageUrl }}" alt=""
                                 class="aspect-[4/3] w-full rounded-token object-cover" loading="lazy">
                        @else
                            <span class="block aspect-[4/3] w-full rounded-token bg-surface-muted" aria-hidden="true"></span>
                        @endif

                        <span class="font-medium text-ink">{{ $option->label }}</span>
                        <span class="text-ink-muted">
                            {{ $option->price->amount === 0 ? '0 Kč' : '+ '.$option->price->format() }}
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endforeach

    <p class="mt-2 text-sm text-ink-muted">Cena doplňků se připočte v košíku.</p>
@endif
