{{--
    Cookie banner.

    In the KERNEL, not in the analytics module: every shop needs it, including
    one that measures nothing, and a module that can be switched off cannot
    carry a legal duty.

    Rendered into EVERY cached page, unconditionally. The visitor's decision
    lives in a cookie, and page-cache entries must not vary by cookie — that
    is exactly the mistake wave 3.0 avoided by dropping `has_cart`. So the
    banner is always in the HTML and JS removes it when a decision exists.
    The inline script in the layout head sets a class on <html> before the
    first paint, so it never flashes for someone who already decided.

    Not a modal, and no focus trap: nothing here blocks reading the page. A
    banner that traps focus makes the shop unusable for keyboard users who
    have not decided yet, and neither the ePrivacy rules nor ÚOOÚ ask for it.
--}}
<div id="cookie-banner"
     class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white shadow-lg"
     role="region"
     aria-label="Souhlas s cookies">
    <div class="mx-auto max-w-4xl px-4 py-4">
        <p class="text-sm text-slate-700">
            Používáme cookies. Ty nezbytné drží košík a přihlášení; analytické a marketingové
            spustíme jen s vaším souhlasem.
            <a href="{{ route('consent.show') }}" class="underline hover:text-slate-900">Podrobné nastavení</a>
        </p>

        <form method="POST" action="{{ route('consent.store') }}" class="mt-3 flex flex-wrap gap-3">
            @csrf

            {{--
                Both buttons carry the SAME classes. Consent is only valid if
                refusing is as easy as accepting — a grey "reject" next to a
                coloured "accept" nudges the choice and makes it unfree
                (EDPB Guidelines 03/2022; ÚOOÚ takes the same line). This is
                asserted in a test, because it cannot be eyeballed in review.
            --}}
            <button type="submit" name="volba" value="vse"
                    class="rounded-md border border-slate-300 bg-slate-100 px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200">
                Přijmout vše
            </button>

            <button type="submit" name="volba" value="nic"
                    class="rounded-md border border-slate-300 bg-slate-100 px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200">
                Odmítnout vše
            </button>
        </form>
    </div>
</div>
