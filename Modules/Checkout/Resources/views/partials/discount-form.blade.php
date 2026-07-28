{{--
    The discount code field — server-rendered, no JS required. Both forms
    below post to a real route and redirect back to a freshly rendered page
    (PRG), exactly like every other cart action on this page.

    $cart is the PricedCart already rendered by the caller; $returnTo is
    'cart' or 'checkout' and is echoed back as a hidden field so the
    controller knows which screen to redirect to — never a URL, so nothing
    here can become an open redirect.

    $discountsEnabled comes from the controller (ShopModules resolved once,
    not from this template) — the whole field is absent when the tenant does
    not run the discounts module.
--}}
@php
    /** @var \Modules\Checkout\Support\PricedCart $cart */
    $applied = collect($cart->discountSources)->first(fn ($source) => $source->code !== null);

    // Two different moments can produce a rejection, and both have to reach
    // this same message: a code just typed and rejected on this very
    // request ($errors, standard Laravel form-error flash — never persisted,
    // see CartDiscountController::apply()), or a code that was valid when it
    // was stored but has since gone stale while the cart sat open (expired,
    // deactivated, usage limit hit elsewhere) — CartPricer re-evaluates the
    // stored code on every render, so that case shows up as
    // $cart->discountRejection with nothing having just been submitted.
    $rejectionMessage = $errors->first('code');

    if ($rejectionMessage === '' && $cart->discountRejection !== null) {
        $rejectionMessage = 'Slevový kód neplatí — '
            .__('discounts.rejection.'.$cart->discountRejection->reason, [], 'cs');
    }
@endphp

@if ($discountsEnabled ?? false)
    <div class="mt-6 border-t border-slate-200 pt-4">
        @if ($applied)
            <form method="POST" action="{{ route('storefront.checkout.discount.remove') }}"
                  class="flex flex-wrap items-center justify-between gap-3">
                @csrf
                <input type="hidden" name="return_to" value="{{ $returnTo ?? 'cart' }}">
                <p class="text-sm text-slate-700">
                    Uplatněn slevový kód <strong>{{ $applied->code }}</strong> — {{ $applied->name }}
                </p>
                <button type="submit" class="text-sm text-red-700 underline hover:text-red-800">
                    Odebrat kód
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('storefront.checkout.discount.apply') }}"
                  class="flex flex-wrap items-end gap-3">
                @csrf
                <input type="hidden" name="return_to" value="{{ $returnTo ?? 'cart' }}">
                <div class="grow">
                    <label for="discount-code" class="field-label">Slevový kód</label>
                    <input
                        id="discount-code"
                        name="code"
                        type="text"
                        autocomplete="off"
                        value="{{ old('code') }}"
                        class="field-input mt-1"
                        @if ($rejectionMessage !== '') aria-invalid="true" aria-describedby="discount-code-error" @endif
                    >
                </div>
                <button type="submit" class="btn btn-outline px-4 py-2">Uplatnit</button>
            </form>

            @if ($rejectionMessage !== '')
                {{-- role="alert" announces the error to assistive tech on its
                     own; the text itself states the problem, so nothing here
                     depends on the red colour to be understood (WCAG 1.4.1). --}}
                <p id="discount-code-error" role="alert" class="mt-2 text-sm text-red-700">
                    {{ $rejectionMessage }}
                </p>
            @endif
        @endif
    </div>
@endif
